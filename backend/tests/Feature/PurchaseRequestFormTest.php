<?php

namespace Tests\Feature;

use App\Models\LibCategory;
use App\Models\PurchaseRequest;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * A purchase request printed in MSU's Purchase Request form (PDF view JSON
 * and CSV): rows and total, Requested by = the Project Leader, signatories
 * from Edit project details, DRAFT before approval, not for Researchers.
 */
class PurchaseRequestFormTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private LibCategory $equipment;
    private LibCategory $travel;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->buildWorkflowFixtures();
        AuditLogger::muted(function () {
            $this->equipment = LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'MOOE', 'line_item_name' => 'Laboratory equipment', 'allocated_amount' => 200000]);
            $this->travel = LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'MOOE', 'line_item_name' => 'Travel', 'allocated_amount' => 50000]);
            // The preparer (Research Assistant) and the Project Leader are in different units.
            $this->assistant->update(['department' => 'Department of Biology', 'college' => 'College of Agriculture']);
            $this->projectLeader->update(['department' => 'Department of Chemistry', 'college' => 'College of Natural Sciences and Mathematics']);
        });
    }

    public function test_the_msu_example_prints_its_rows_and_the_105500_total(): void
    {
        $request = $this->create();
        $doc = $this->actingAs($this->assistant)->getJson($this->formUrl($request))->assertOk()->json();

        $this->assertSame('PURCHASE REQUEST', $doc['header']['title']);
        $this->assertSame('Mindanao State University', $doc['header']['university']);
        $this->assertSame('Marawi City', $doc['header']['city']);
        $this->assertSame(['Item No.', 'Qty', 'Unit', 'Item Description', 'Estimated Unit Cost', 'Estimated Cost'], $doc['columns']);
        $this->assertSame([1, 2, 3], array_column($doc['rows'], 'item_no'));
        $this->assertSame([2, 2, 1], array_column($doc['rows'], 'quantity'));
        $this->assertSame(['unit', 'box', 'lot'], array_column($doc['rows'], 'unit'));
        $this->assertEquals([40000, 6000, 13500], array_column($doc['rows'], 'unit_cost'));
        $this->assertEquals([80000, 12000, 13500], array_column($doc['rows'], 'estimated_cost'));
        $this->assertEquals(105500, $doc['total']);
        $this->assertSame('Laboratory set-up for the field study', $doc['purpose']);
        $this->assertSame('CHARGEABLE AGAINST MOST-BARMM FUNDED PROJECT', $doc['chargeable']);
        $this->assertSame('Workflow project', $doc['project_title']);
        // Department and college of the Project Leader (the "Requested by"), not of the Research Assistant who prepared it.
        $this->assertSame('Department of Chemistry, College of Natural Sciences and Mathematics', $doc['department']);
        $this->assertStringNotContainsString('Biology', $doc['department']);
        $this->assertNotSame('', $doc['office_unit']);
        $this->assertSame("PR - WF-001 - {$request->id}", $doc['title']);
        // PR / SAI / ALOBS not set yet: blank lines.
        $this->assertSame([null, null, null], array_column($doc['numbers'], 'value'));
        $this->assertSame([null, null, null], array_column($doc['numbers'], 'date'));

        $csv = $this->get($this->formUrl($request) . '/csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $lines = preg_split('/\r\n/', ltrim($csv, "\xEF\xBB\xBF"));
        $this->assertContains('Item No.,Qty,Unit,Item Description,Estimated Unit Cost,Estimated Cost', $lines);
        $this->assertContains('1,2,unit,Digital microscope,40000.00,80000.00', $lines);
        $this->assertContains('2,2,box,Specimen containers,6000.00,12000.00', $lines);
        $this->assertContains('3,1,lot,Transport of samples,13500.00,13500.00', $lines);
        $this->assertContains('TOTAL,,,,,105500.00', $lines);
        $this->assertContains('CHARGEABLE AGAINST MOST-BARMM FUNDED PROJECT', $lines);
        $this->assertStringNotContainsString('₱', $csv);
    }

    public function test_requested_by_is_the_project_leader_even_when_an_assistant_prepared_it(): void
    {
        $request = $this->create();
        $this->assertSame($this->assistant->id, (int) $request->requested_by);

        $signatories = collect($this->actingAs($this->assistant)->getJson($this->formUrl($request))->json('signatories'))->keyBy('label');
        $this->assertSame(['Requested by:', 'Noted by:', 'Approved by:'], $signatories->keys()->all());
        $this->assertSame($this->projectLeader->name, $signatories['Requested by:']['name']);
        $this->assertNotSame($this->assistant->name, $signatories['Requested by:']['name']);
    }

    public function test_signatories_print_only_when_set_in_edit_project_details(): void
    {
        $request = $this->create();
        $blank = collect($this->actingAs($this->assistant)->getJson($this->formUrl($request))->json('signatories'))->keyBy('label');
        $this->assertNull($blank['Requested by:']['position']);
        $this->assertNull($blank['Noted by:']['name']);
        $this->assertNull($blank['Approved by:']['name']);

        $this->actingAs($this->programLeader)->patchJson("/api/program/projects/{$this->project->id}", [
            'pr_requested_by_position' => 'Project Leader',
            'pr_noted_by_name' => 'Noter Placeholder',
            'pr_noted_by_position' => 'Department Chair',
            'pr_approved_by_name' => '   ',
        ])->assertOk();
        $this->assertSame('Noter Placeholder', $this->getJson("/api/program/projects/{$this->project->id}")->json('setup.purchase_request.pr_noted_by_name'));

        $set = collect($this->getJson($this->formUrl($request))->json('signatories'))->keyBy('label');
        $this->assertSame([$this->projectLeader->name, 'Project Leader'], [$set['Requested by:']['name'], $set['Requested by:']['position']]);
        $this->assertSame(['Noter Placeholder', 'Department Chair'], [$set['Noted by:']['name'], $set['Noted by:']['position']]);
        $this->assertNull($set['Approved by:']['name']);

        $csv = $this->get($this->formUrl($request) . '/csv')->getContent();
        $this->assertStringContainsString("Noted by:,Noter Placeholder,Department Chair\r\n", $csv);
        $this->assertStringEndsWith("\r\nApproved by:", $csv);

        // Only the Program Leader edits them; long values are rejected.
        $this->patchJson("/api/program/projects/{$this->project->id}", ['pr_noted_by_name' => str_repeat('x', 256)])
            ->assertStatus(422)->assertJsonValidationErrors(['pr_noted_by_name']);
        $this->actingAs($this->projectLeader)->patchJson("/api/program/projects/{$this->project->id}", ['pr_noted_by_name' => 'X'])->assertForbidden();
    }

    public function test_only_drafts_and_waiting_requests_are_marked_draft(): void
    {
        $request = $this->create();
        $this->actingAs($this->assistant);
        $this->assertTrue($this->getJson($this->formUrl($request))->json('draft'));
        $this->assertStringContainsString("DRAFT,Not approved yet\r\n", $this->get($this->formUrl($request) . '/csv')->getContent());

        $this->attachQuotation($request);
        $this->postJson("/api/purchase-requests/{$request->id}/submit")->assertOk();
        $this->assertTrue($this->getJson($this->formUrl($request))->json('draft'));

        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$request->id}/endorse")->assertOk();
        $this->assertFalse($this->getJson($this->formUrl($request))->json('draft'));
        $this->assertStringNotContainsString('DRAFT', $this->get($this->formUrl($request) . '/csv')->getContent());

        // PR No. set by Finance/Admin: the title and the form use it.
        $this->actingAs($this->finance)->patchJson("/api/purchase-requests/{$request->id}/numbers", ['pr_no' => '2026-11-001', 'pr_date' => '2026-11-20'])->assertOk();
        $doc = $this->getJson($this->formUrl($request))->json();
        $this->assertSame('PR - WF-001 - 2026-11-001', $doc['title']);
        $this->assertSame(['2026-11-001', 'November 20, 2026'], [$doc['numbers'][0]['value'], $doc['numbers'][0]['date']]);
        $this->assertFalse($doc['draft']);
    }

    public function test_researchers_and_outsiders_cannot_download_it(): void
    {
        $request = $this->create();
        $this->actingAs($this->researcher)->getJson($this->formUrl($request))->assertForbidden();
        $this->actingAs($this->researcher)->get($this->formUrl($request) . '/csv')->assertForbidden();
        $this->actingAs($this->outsider)->getJson($this->formUrl($request))->assertForbidden();

        foreach ([$this->assistant, $this->projectLeader, $this->finance, $this->programLeader] as $user) {
            $this->actingAs($user)->getJson($this->formUrl($request))->assertOk();
            $this->actingAs($user)->get($this->formUrl($request) . '/csv')->assertOk();
        }
    }

    private function create(): PurchaseRequest
    {
        $id = $this->actingAs($this->assistant)->postJson("/api/projects/{$this->project->id}/purchase-requests", [
            'purpose' => 'Laboratory set-up for the field study',
            'supplier' => 'ABC Lab Supply',
            'items' => [
                ['lib_category_id' => $this->equipment->id, 'quantity' => 2, 'unit' => 'unit', 'description' => 'Digital microscope', 'unit_cost' => 40000],
                ['lib_category_id' => $this->lineItem->id, 'quantity' => 2, 'unit' => 'box', 'description' => 'Specimen containers', 'unit_cost' => 6000],
                ['lib_category_id' => $this->travel->id, 'quantity' => 1, 'unit' => 'lot', 'description' => 'Transport of samples', 'unit_cost' => 13500],
            ],
        ])->assertCreated()->json('purchase_request.id');

        return PurchaseRequest::findOrFail($id);
    }

    private function attachQuotation(PurchaseRequest $request): void
    {
        AuditLogger::muted(fn () => $request->documents()->create([
            'uploaded_by' => $this->assistant->id, 'document_type' => 'quotation', 'file_name' => 'q.pdf',
            'file_path' => 'documents/purchase-requests/q.pdf', 'disk' => 'local', 'uploaded_at' => now(),
        ]));
    }

    private function formUrl(PurchaseRequest $request): string
    {
        return "/api/purchase-requests/{$request->id}/form";
    }
}
