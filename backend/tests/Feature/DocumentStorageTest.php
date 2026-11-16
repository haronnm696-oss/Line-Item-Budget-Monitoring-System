<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\DocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Document bytes stored in the database (DOCUMENTS_DISK=database): put,
 * stream, delete, the 5 MB limit, duplicate refusal, download access, the
 * import command, and that no JSON response ever carries file contents.
 */
class DocumentStorageTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    /** Binary-safe sample bytes (NUL, high bytes, CR/LF). */
    private const BYTES = "%PDF-1.4\n\x00\x01\xfe\xff receipt OR-2841 \r\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        config(['documents.disk' => DocumentStorage::DATABASE]);
    }

    private function pdf(string $bytes = self::BYTES, string $name = 'receipt.pdf', int $kb = 0)
    {
        $file = UploadedFile::fake()->createWithContent($name, $bytes)->mimeType('application/pdf');

        return $kb > 0 ? $file->size($kb) : $file;
    }

    private function upload(int $expenseId, $file, string $type = 'receipt')
    {
        return $this->actingAs($this->assistant)
            ->post("/api/expenses/{$expenseId}/documents", ['file' => $file, 'document_type' => $type], ['Accept' => 'application/json']);
    }

    public function test_put_get_and_delete_in_the_database(): void
    {
        $expense = $this->expenseIn('draft', null, false);
        $id = $this->upload($expense->id, $this->pdf())->assertCreated()->json('document.id');

        $document = Document::findOrFail($id);
        $this->assertSame(DocumentStorage::DATABASE, $document->disk);
        $row = DB::table('document_contents')->where('document_id', $id)->first();
        $this->assertSame(hash('sha256', self::BYTES), $row->sha256);
        $this->assertSame(strlen(self::BYTES), (int) $row->size);

        $storage = app(DocumentStorage::class);
        $this->assertTrue($storage->exists($document));
        $this->assertSame(self::BYTES, $storage->contents($document));
        $this->assertSame(self::BYTES, stream_get_contents($storage->get($document)));

        // Download: attachment; preview: inline. Same bytes, stored Content-Type.
        $download = $this->get("/api/documents/{$id}/download")->assertOk();
        $this->assertSame(self::BYTES, $download->streamedContent());
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment; filename="receipt.pdf"', $download->headers->get('Content-Disposition'));
        $preview = $this->get("/api/documents/{$id}/download?inline=1")->assertOk();
        $this->assertStringStartsWith('inline; filename="receipt.pdf"', $preview->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $preview->headers->get('X-Content-Type-Options'));

        // Delete removes the row and its bytes.
        $this->deleteJson("/api/documents/{$id}")->assertOk();
        $this->assertNull(Document::find($id));
        $this->assertFalse(DB::table('document_contents')->where('document_id', $id)->exists());
    }

    public function test_the_limit_is_five_megabytes(): void
    {
        $expense = $this->expenseIn('draft', null, false);

        $this->upload($expense->id, $this->pdf('a', 'big.pdf', 5121))
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'The file is too large. The limit is 5 MB per file.');
        $this->assertSame(0, $expense->documents()->count());

        $this->upload($expense->id, $this->pdf('b', 'exactly-5mb.pdf', 5120))->assertCreated();
        $this->upload($expense->id, UploadedFile::fake()->createWithContent('notes.txt', 'x')->mimeType('text/plain'))
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Upload a PDF, JPG or PNG file.');
    }

    public function test_an_exact_duplicate_on_the_same_record_is_refused(): void
    {
        $expense = $this->expenseIn('draft', null, false);
        $other = $this->expenseIn('draft', null, false);

        $this->upload($expense->id, $this->pdf())->assertCreated();
        // Same bytes, another file name: still a duplicate.
        $this->upload($expense->id, $this->pdf(self::BYTES, 'copy-of-receipt.pdf'), 'invoice')
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'This file is already attached to this record.');
        $this->assertSame(1, $expense->documents()->count());

        // Different bytes on the same record, or the same bytes on another record: fine.
        $this->upload($expense->id, $this->pdf(self::BYTES . ' page 2'))->assertCreated();
        $this->upload($other->id, $this->pdf())->assertCreated();
    }

    public function test_download_follows_the_project_access_rules(): void
    {
        $expense = $this->expenseIn('submitted', null, false);
        $id = $this->uploadAsDraftThenSubmit($expense);

        foreach ([$this->assistant, $this->projectLeader, $this->finance, $this->researcher, $this->programLeader] as $user) {
            $this->assertSame(self::BYTES, $this->actingAs($user)->get("/api/documents/{$id}/download")->assertOk()->streamedContent());
        }
        $this->actingAs($this->outsider)->get("/api/documents/{$id}/download", ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($this->outsider)->get("/api/documents/{$id}/download?inline=1", ['Accept' => 'application/json'])->assertForbidden();
        auth()->forgetGuards();
        $this->getJson("/api/documents/{$id}/download")->assertUnauthorized();
    }

    public function test_list_endpoints_never_return_file_contents(): void
    {
        $expense = $this->expenseIn('draft', null, false);
        $id = $this->upload($expense->id, $this->pdf())->assertCreated()->json('document.id');
        $p = $this->project->id;

        $responses = [
            $this->actingAs($this->assistant)->getJson("/api/expenses/{$expense->id}/documents"),
            $this->getJson("/api/projects/{$p}/documents"),
            $this->getJson("/api/projects/{$p}/my-submissions?tab=all"),
            $this->getJson("/api/projects/{$p}/my-submissions/expense/{$expense->id}"),
            $this->getJson("/api/projects/{$p}/expenses"),
            $this->actingAs($this->projectLeader)->getJson("/api/projects/{$p}/review-queue?tab=returned"),
        ];
        foreach ($responses as $response) {
            $response->assertOk();
            $body = $response->getContent();
            $this->assertStringNotContainsString('receipt OR-2841', $body);
            $this->assertStringNotContainsString(base64_encode(self::BYTES), $body);
            $this->assertStringNotContainsString('"content"', $body);
        }
        $listed = $this->actingAs($this->assistant)->getJson("/api/expenses/{$expense->id}/documents")->json('documents.0');
        $this->assertSame(['id', 'document_type', 'file_name', 'size', 'mime', 'uploaded_at', 'uploaded_by', 'download_url', 'available'], array_keys($listed));

        // The documents table itself has no bytes; a plain model query never loads them.
        $this->assertArrayNotHasKey('content', Document::findOrFail($id)->getAttributes());
    }

    public function test_the_import_command_copies_present_files_and_marks_missing_ones(): void
    {
        Storage::fake('local');
        $expense = $this->expenseIn('verified', null, false);
        $present = $this->receiptFor($expense, $this->assistant, 'documents/expenses/present.pdf');
        Storage::disk('local')->put($present->file_path, self::BYTES);
        $missing = $this->receiptFor($expense, $this->assistant, 'documents/expenses/gone.pdf');
        $unknownDisk = $this->receiptFor($expense, $this->assistant, 'documents/expenses/elsewhere.pdf');
        $unknownDisk->forceFill(['disk' => 'no-such-disk'])->save();

        // Dry run: reports, changes nothing.
        $this->artisan('documents:import-to-database', ['--dry-run' => true])
            ->expectsOutput('[dry run] Would import: 1')
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('document_contents')->count());
        $this->assertNull($missing->fresh()->file_missing_at);
        $this->assertSame('local', $present->fresh()->disk);

        $this->artisan('documents:import-to-database')
            ->expectsOutput('Imported: 1')
            ->expectsOutput('File missing (marked, rows kept): 2')
            ->assertExitCode(0);

        $present->refresh();
        $this->assertSame(DocumentStorage::DATABASE, $present->disk);
        $this->assertSame(self::BYTES, app(DocumentStorage::class)->contents($present));
        $this->assertNotNull($missing->fresh()->file_missing_at);
        $this->assertNotNull($unknownDisk->fresh()->file_missing_at);
        $this->assertSame(3, $expense->documents()->count()); // no row deleted

        // The UI is told; the download says so.
        $rows = collect($this->actingAs($this->assistant)->getJson("/api/expenses/{$expense->id}/documents")->json('documents'))->keyBy('id');
        $this->assertTrue($rows[$present->id]['available']);
        $this->assertFalse($rows[$missing->id]['available']);
        $this->getJson("/api/documents/{$missing->id}/download")->assertNotFound()->assertJsonPath('message', 'File not available.');

        // Idempotent: a second run imports nothing new.
        $this->artisan('documents:import-to-database')
            ->expectsOutput('Imported: 0')
            ->expectsOutput('Already in the database: 1')
            ->assertExitCode(0);
        $this->assertSame(1, DB::table('document_contents')->count());

        // A file that turns up later is imported and the mark is cleared.
        Storage::disk('local')->put($missing->file_path, 'found again');
        $this->artisan('documents:import-to-database')->expectsOutput('Imported: 1')->assertExitCode(0);
        $this->assertNull($missing->fresh()->file_missing_at);
        $this->assertSame('found again', app(DocumentStorage::class)->contents($missing->fresh()));
    }

    /** Upload a receipt while the expense is a draft, then move it to submitted. */
    private function uploadAsDraftThenSubmit($expense): int
    {
        $expense->forceFill(['workflow_status' => 'draft'])->saveQuietly();
        $id = $this->upload($expense->id, $this->pdf())->assertCreated()->json('document.id');
        $expense->forceFill(['workflow_status' => 'submitted'])->saveQuietly();

        return $id;
    }
}
