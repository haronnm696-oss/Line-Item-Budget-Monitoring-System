<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\Document;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\FormBReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Step 1 of the LIB process, project set-up from the Memorandum of
 * Agreement: the MOA fields, the fund source on Form B, the project period
 * in words, and the project documents (MOA, Forms A/B/C, other).
 * Fixture project period: 2026-01-01 .. 2026-12-31.
 */
class ProjectAgreementTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private const MOA = [
        'moa_number' => 'MOA No. 2026-014',
        'moa_date' => '2025-12-10',
        'rd_committee_approved_date' => '2025-11-28',
        'fund_source' => 'MOST-BARMM GAAB 2024 Program Funds',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    private function newProject(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Seaweed Value Chain', 'code' => 'RP-2026-010',
            'funding_agency_id' => $this->project->funding_agency_id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_approved_budget' => 750000,
            'project_leader_id' => $this->freeLeader()->id,
        ], $overrides);
    }

    // ---------------------------------------------------------------- MOA fields

    public function test_moa_fields_are_saved_on_create_and_audited(): void
    {
        $audit = AuditTrail::count();
        $id = $this->actingAs($this->programLeader)->postJson('/api/program/projects', $this->newProject(self::MOA))
            ->assertCreated()->json('project.id');

        $project = Project::findOrFail($id);
        $this->assertSame('MOA No. 2026-014', $project->moa_number);
        $this->assertSame('2025-12-10', $project->moa_date->toDateString());
        $this->assertSame('2025-11-28', $project->rd_committee_approved_date->toDateString());
        $this->assertSame('MOST-BARMM GAAB 2024 Program Funds', $project->fund_source);

        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        foreach (self::MOA as $key => $value) {
            $this->assertSame($value, $entry->new_values[$key], $key);
        }

        // All optional: a project without them is fine.
        $this->postJson('/api/program/projects', $this->newProject(['code' => 'RP-2026-011', 'project_leader_id' => AuditLogger::muted(fn () => $this->makePerson('project-leader'))->id]))->assertCreated();
        $this->assertNull(Project::where('code', 'RP-2026-011')->value('fund_source'));
    }

    public function test_moa_fields_are_edited_cleared_and_audited(): void
    {
        $url = "/api/program/projects/{$this->project->id}";
        $audit = AuditTrail::count();
        $this->actingAs($this->programLeader)->patchJson($url, self::MOA)->assertOk();

        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame('updated', $entry->action);
        $this->assertNull($entry->old_values['moa_number']);
        $this->assertSame('MOA No. 2026-014', $entry->new_values['moa_number']);
        $this->assertSame('2025-12-10', $entry->new_values['moa_date']);

        // Shown on both project pages, under the title.
        $expected = self::MOA + ['duration' => 'Twelve (12) months'];
        $this->getJson("/api/program/projects/{$this->project->id}")->assertOk()
            ->assertJsonPath('project.agreement', $expected)
            ->assertJsonPath('setup.agreement', $expected);
        $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}")->assertOk()
            ->assertJsonPath('project.agreement', $expected);

        // The same values again: nothing logged. Blank text clears a field.
        $this->actingAs($this->programLeader)->patchJson($url, self::MOA)->assertOk();
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->patchJson($url, ['moa_number' => '  ', 'moa_date' => null])->assertOk();
        $fresh = $this->project->fresh();
        $this->assertNull($fresh->moa_number);
        $this->assertNull($fresh->moa_date);
        $this->assertSame('MOST-BARMM GAAB 2024 Program Funds', $fresh->fund_source);

        // Validation.
        $this->patchJson($url, ['moa_date' => 'not a date', 'fund_source' => str_repeat('x', 256), 'moa_number' => str_repeat('x', 101)])
            ->assertStatus(422)->assertJsonValidationErrors(['moa_date', 'fund_source', 'moa_number']);
    }

    public function test_only_the_program_leader_sets_moa_fields(): void
    {
        foreach ([$this->projectLeader, $this->finance, $this->researcher, $this->assistant] as $user) {
            $this->actingAs($user)->patchJson("/api/program/projects/{$this->project->id}", self::MOA)->assertForbidden();
        }
        $this->assertNull($this->project->fresh()->moa_number);
    }

    // ---------------------------------------------------------------- Form B

    public function test_form_b_prints_the_fund_source_when_set(): void
    {
        $url = "/api/projects/{$this->project->id}/form-b";
        $this->actingAs($this->programLeader)->getJson($url)->assertOk()
            ->assertJsonPath('footer.note', '* Chargeable against the CY 2026');

        $this->patchJson("/api/program/projects/{$this->project->id}", ['fund_source' => 'MOST-BARMM GAAB 2024 Program Funds'])->assertOk();
        $this->getJson($url)->assertOk()
            ->assertJsonPath('footer.note', '* Chargeable against MOST-BARMM GAAB 2024 Program Funds')
            ->assertJsonPath('header.year', 'CY 2026');

        $csv = app(FormBReportService::class)->csvRows(app(FormBReportService::class)->build($this->project->fresh()));
        $this->assertContains('* Chargeable against MOST-BARMM GAAB 2024 Program Funds', array_merge(...$csv));
    }

    // ---------------------------------------------------------------- period

    public function test_the_end_date_must_be_after_the_start_date(): void
    {
        $this->actingAs($this->programLeader);
        $this->postJson('/api/program/projects', $this->newProject(['end_date' => '2026-01-01']))
            ->assertStatus(422)->assertJsonPath('errors.end_date.0', 'The end date must be after the start date.');
        $this->patchJson("/api/program/projects/{$this->project->id}", ['end_date' => '2026-01-01'])
            ->assertStatus(422)->assertJsonPath('errors.end_date.0', 'The end date must be after the start date.');
        $this->patchJson("/api/program/projects/{$this->project->id}", ['start_date' => '2026-03-01', 'end_date' => '2026-02-01'])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->patchJson("/api/program/projects/{$this->project->id}", ['end_date' => '2026-01-02'])->assertOk();
    }

    public function test_the_duration_is_in_words_like_form_b(): void
    {
        $service = app(FormBReportService::class);
        $cases = [
            ['2026-01-01', '2026-12-31', 'Twelve (12) months'],
            ['2026-01-01', '2026-01-31', 'One (1) month'],
            ['2026-01-01', '2026-01-02', 'One (1) month'],
            ['2026-01-01', '2027-06-30', 'Eighteen (18) months'],
            ['2026-01-15', '2028-01-14', 'Twenty-four (24) months'],
            ['2026-01-31', '2026-02-27', 'One (1) month'],
            ['2026-01-01', '2026-02-01', 'Two (2) months'],
        ];
        foreach ($cases as [$start, $end, $words]) {
            $this->assertSame($words, $service->duration(Carbon::parse($start), Carbon::parse($end)), "{$start}..{$end}");
        }

        // The project page and Form B show the same words.
        $this->actingAs($this->programLeader);
        $this->getJson("/api/program/projects/{$this->project->id}")->assertJsonPath('project.agreement.duration', 'Twelve (12) months');
        $details = collect($this->getJson("/api/projects/{$this->project->id}/form-b")->json('details'))->pluck('value', 'label');
        $this->assertSame('Twelve (12) months', $details['Total Duration']);
    }

    // ---------------------------------------------------------------- documents

    private function upload(string $type = 'moa', ?UploadedFile $file = null)
    {
        return $this->postJson("/api/program/projects/{$this->project->id}/documents", [
            'file' => $file ?? UploadedFile::fake()->createWithContent('moa.pdf', '%PDF-1.4 MOA 2026-014'),
            'document_type' => $type,
        ]);
    }

    public function test_the_program_leader_uploads_and_removes_with_one_audit_entry_each(): void
    {
        $audit = AuditTrail::count();
        $id = $this->actingAs($this->programLeader)->upload()->assertCreated()
            ->assertJsonPath('document.document_type', 'moa')->json('document.id');

        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame(['created', 'Document'], [$entry->action, $entry->model_type]);
        $this->assertSame($this->project->id, (int) $entry->project_id);
        $this->assertSame('moa', $entry->new_values['document_type']);

        $list = $this->getJson("/api/projects/{$this->project->id}/project-documents")->assertOk();
        $this->assertSame('manage', $list->json('access'));
        $this->assertSame(['moa', 'form_a', 'form_b', 'form_c', 'other'], collect($list->json('types'))->pluck('value')->all());
        $this->assertSame([$id], collect($list->json('documents'))->pluck('id')->all());
        $this->assertSame('Memorandum of Agreement (MOA)', $list->json('documents.0.type_label'));

        // The same file again is refused (same check as receipts).
        $this->upload('other')->assertStatus(422)->assertJsonPath('errors.file.0', 'This file is already attached to this record.');
        $this->assertSame($audit + 1, AuditTrail::count());

        $this->deleteJson("/api/program/project-documents/{$id}")->assertOk();
        $this->assertSame($audit + 2, AuditTrail::count());
        $this->assertSame('deleted', AuditTrail::latest('id')->value('action'));
        $this->assertSame(0, Document::count());
    }

    public function test_file_rules_match_receipts(): void
    {
        $this->actingAs($this->programLeader);
        $this->upload('moa', UploadedFile::fake()->create('moa.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'Upload a PDF, JPG or PNG file.');
        $this->upload('moa', UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf'))
            ->assertStatus(422)->assertJsonPath('errors.file.0', 'The file is too large. The limit is 5 MB per file.');
        $this->upload('receipt')->assertStatus(422)->assertJsonValidationErrors(['document_type']);
        foreach (['form_a', 'form_b', 'form_c'] as $i => $type) {
            $this->upload($type, UploadedFile::fake()->createWithContent("{$type}.png", "png {$i}"))->assertCreated();
        }
    }

    public function test_only_the_program_leader_uploads_or_removes(): void
    {
        $id = $this->actingAs($this->programLeader)->upload()->assertCreated()->json('document.id');
        $audit = AuditTrail::count();

        foreach ([$this->projectLeader, $this->finance, $this->researcher, $this->assistant, $this->outsider] as $user) {
            $this->actingAs($user);
            $this->upload('form_a', UploadedFile::fake()->createWithContent('a.pdf', 'other bytes'))->assertForbidden();
            $this->deleteJson("/api/program/project-documents/{$id}")->assertForbidden();
            $this->deleteJson("/api/documents/{$id}")->assertForbidden();
        }
        $this->assertSame($audit, AuditTrail::count());
        $this->assertSame(1, Document::count());
    }

    public function test_who_views_and_downloads(): void
    {
        $id = $this->actingAs($this->programLeader)->upload()->assertCreated()->json('document.id');
        $list = "/api/projects/{$this->project->id}/project-documents";
        $download = "/api/documents/{$id}/download";

        // Every member previews and downloads, Research Assistants included
        // (they process the project's papers).
        foreach ([$this->programLeader, $this->projectLeader, $this->finance, $this->researcher, $this->assistant] as $user) {
            $this->actingAs($user)->getJson($list)->assertOk()->assertJsonPath('documents.0.id', $id);
            $this->get($download)->assertOk()->assertHeader('Content-Disposition', "attachment; filename=\"moa.pdf\"; filename*=UTF-8''moa.pdf");
            $this->get("{$download}?inline=1")->assertOk();
        }
        $this->actingAs($this->programLeader)->getJson($list)->assertJsonPath('access', 'manage');
        foreach ([$this->projectLeader, $this->finance, $this->researcher, $this->assistant] as $user) {
            $this->actingAs($user)->getJson($list)->assertJsonPath('access', 'download');
        }

        // Not a member of the project.
        $this->actingAs($this->outsider)->getJson($list)->assertForbidden();
        $this->get("{$download}?inline=1")->assertForbidden();
    }

    public function test_removing_a_document_of_another_record_is_refused(): void
    {
        $expense = $this->expenseIn('submitted', $this->assistant, true);
        $doc = Document::where('documentable_id', $expense->id)->firstOrFail();

        $this->actingAs($this->programLeader)->deleteJson("/api/program/project-documents/{$doc->id}")->assertNotFound();
        $this->assertNotNull($doc->fresh());
    }
}
