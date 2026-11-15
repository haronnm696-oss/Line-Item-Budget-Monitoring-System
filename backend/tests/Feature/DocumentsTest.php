<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Documents attached to an expense: PDF/JPG/PNG up to 10 MB, uploaded by a
 * Research Assistant or Finance/Admin of the project while the expense is a
 * draft or returned; listed and downloaded by project members only; deleted
 * only by the uploader and never after verification. One audit entry for
 * each upload and delete.
 */
class DocumentsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->buildWorkflowFixtures();
    }

    public function test_an_encoder_uploads_a_receipt_with_one_audit_entry(): void
    {
        $expense = $this->expenseIn('draft', null, false);
        $audit = AuditTrail::count();

        $response = $this->actingAs($this->assistant)
            ->post("/api/expenses/{$expense->id}/documents", [
                'file' => UploadedFile::fake()->create('OR-1001.pdf', 200, 'application/pdf'),
                'document_type' => 'receipt',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('document.file_name', 'OR-1001.pdf')
            ->assertJsonPath('document.document_type', 'receipt')
            ->assertJsonPath('document.uploaded_by.id', $this->assistant->id);

        $document = Document::findOrFail($response->json('document.id'));
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertStringStartsWith("documents/expenses/{$expense->id}/", $document->file_path);
        $this->assertSame('local', $document->disk);

        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame(['created', 'Document', $this->project->id], [$entry->action, $entry->model_type, (int) $entry->project_id]);

        // The expense can now be submitted.
        $this->postJson("/api/expenses/{$expense->id}/submit")->assertOk();
    }

    public function test_file_type_size_and_document_type_are_validated(): void
    {
        $expense = $this->expenseIn('draft', null, false);
        $this->actingAs($this->assistant);
        $upload = fn (array $data) => $this->post("/api/expenses/{$expense->id}/documents", $data, ['Accept' => 'application/json']);

        $upload(['file' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain'), 'document_type' => 'receipt'])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Upload a PDF, JPG or PNG file.');
        $upload(['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'), 'document_type' => 'receipt'])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'The file must not be larger than 10 MB.');
        $upload(['file' => UploadedFile::fake()->create('ok.pdf', 10, 'application/pdf'), 'document_type' => 'selfie'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document_type']);

        $upload(['file' => UploadedFile::fake()->create('receipt.png', 50, 'image/png'), 'document_type' => 'receipt'])->assertCreated();
        $upload(['file' => UploadedFile::fake()->create('invoice.jpg', 50, 'image/jpeg'), 'document_type' => 'invoice'])->assertCreated();
        $upload(['file' => UploadedFile::fake()->create('max.pdf', 10240, 'application/pdf'), 'document_type' => 'quotation'])->assertCreated();

        $this->assertSame(3, $expense->documents()->count());
    }

    public function test_upload_is_limited_to_encoders_of_the_project_and_editable_stages(): void
    {
        $draft = $this->expenseIn('draft', null, false);
        $submitted = $this->expenseIn('submitted');
        $file = fn () => ['file' => UploadedFile::fake()->create('r.pdf', 10, 'application/pdf'), 'document_type' => 'receipt'];

        foreach ([$this->projectLeader, $this->researcher, $this->programLeader, $this->outsider] as $user) {
            $this->actingAs($user)->post("/api/expenses/{$draft->id}/documents", $file(), ['Accept' => 'application/json'])->assertForbidden();
        }
        $this->actingAs($this->assistant)
            ->post("/api/expenses/{$submitted->id}/documents", $file(), ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, $draft->documents()->count());
        $this->assertSame(1, $submitted->documents()->count());
    }

    public function test_only_project_members_list_and_download_documents(): void
    {
        $expense = $this->expenseIn('submitted', null, false);
        $document = $this->receiptFor($expense, $this->assistant);
        Storage::disk('local')->put($document->file_path, 'PDF-CONTENT');

        foreach ([$this->assistant, $this->projectLeader, $this->finance, $this->researcher, $this->programLeader] as $user) {
            $this->actingAs($user)->getJson("/api/expenses/{$expense->id}/documents")->assertOk()->assertJsonCount(1, 'documents');
            $download = $this->actingAs($user)->get("/api/documents/{$document->id}/download")->assertOk();
            $this->assertSame('PDF-CONTENT', $download->streamedContent());
        }

        $this->actingAs($this->outsider);
        $this->getJson("/api/expenses/{$expense->id}/documents")->assertForbidden();
        $this->getJson("/api/documents/{$document->id}/download")->assertForbidden();
        $this->deleteJson("/api/documents/{$document->id}")->assertForbidden();

        // The row exists but the file is gone.
        Storage::disk('local')->delete($document->file_path);
        $this->actingAs($this->projectLeader)
            ->getJson("/api/documents/{$document->id}/download")
            ->assertNotFound()
            ->assertJsonPath('message', 'File unavailable.');
    }

    public function test_only_the_uploader_deletes_and_never_after_verification(): void
    {
        $expense = $this->expenseIn('draft', null, false);
        $document = $this->receiptFor($expense, $this->assistant);
        Storage::disk('local')->put($document->file_path, 'x');

        $this->actingAs($this->finance)->deleteJson("/api/documents/{$document->id}")->assertForbidden();

        $audit = AuditTrail::count();
        $this->actingAs($this->assistant)->deleteJson("/api/documents/{$document->id}")->assertOk();
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame(['deleted', 'Document'], [AuditTrail::latest('id')->value('action'), AuditTrail::latest('id')->value('model_type')]);
        $this->assertModelMissing($document);
        Storage::disk('local')->assertMissing($document->file_path);

        $verified = $this->expenseIn('verified');
        $kept = $verified->documents()->firstOrFail();
        $this->deleteJson("/api/documents/{$kept->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Documents of a verified expense cannot be deleted.');
        $this->assertModelExists($kept);
    }

    public function test_missing_receipts_are_verified_expenses_without_a_receipt_document(): void
    {
        $this->expenseIn('verified');                    // receipt on file
        $this->expenseIn('verified', null, false);       // missing
        $this->expenseIn('submitted', null, false);      // not verified yet: not counted as missing
        $invoiceOnly = $this->expenseIn('verified', null, false);
        $invoiceOnly->documents()->create([
            'uploaded_by' => $this->assistant->id, 'document_type' => 'invoice', 'file_name' => 'inv.pdf',
            'file_path' => 'documents/expenses/inv.pdf', 'disk' => 'local', 'uploaded_at' => now(),
        ]);                                              // an invoice is not a receipt: missing

        $compliance = $this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/compliance")
            ->assertOk();

        $compliance->assertJsonPath('verified', 3)
            ->assertJsonPath('verified_with_receipt', 1)
            ->assertJsonPath('missing_receipt', 2)
            ->assertJsonPath('compliance_pct', 33.3);
    }
}
