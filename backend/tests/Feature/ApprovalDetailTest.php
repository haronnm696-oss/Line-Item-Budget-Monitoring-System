<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\LibCategory;
use App\Models\PayrollBatch;
use App\Models\ProjectTranche;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * GET /api/approvals/{approval}: the Program Leader's review panel. It
 * returns the attachments and the figures of each request type; only the
 * Program Leader and members of the project may see it or its files.
 */
class ApprovalDetailTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private ProjectTranche $first;
    private ProjectTranche $second;
    private LibCategory $equipment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->buildWorkflowFixtures();
        [$this->first, $this->second, $this->equipment] = AuditLogger::muted(fn () => [
            ProjectTranche::create([
                'project_id' => $this->project->id, 'sequence' => 1, 'name' => 'First tranche', 'amount' => 300000,
                'status' => 'released', 'planned_release_date' => '2026-01-10', 'released_at' => '2026-01-15',
            ]),
            ProjectTranche::create([
                'project_id' => $this->project->id, 'sequence' => 2, 'name' => 'Second tranche', 'amount' => 250000,
                'status' => 'due', 'planned_release_date' => '2026-12-01',
            ]),
            LibCategory::create([
                'project_id' => $this->project->id, 'category_type' => 'MOOE',
                'line_item_name' => 'Equipment', 'allocated_amount' => 50000,
            ]),
        ]);
    }

    public function test_a_fund_release_shows_the_tranche_releases_budget_history_and_attachments(): void
    {
        $this->expenseIn('verified', null, false, ['amount' => 4000]);
        $id = $this->create([
            'type' => 'fund_release', 'project_tranche_id' => $this->second->id, 'justification' => 'Fieldwork starts in December.',
            'files' => [$this->uniqueUpload('work-plan.pdf')],
        ]);

        $detail = $this->detail($id);
        $this->assertSame('fund_release', $detail['details']['kind']);
        $this->assertSame(
            ['Second tranche', 250000.0, '2026-12-01'],
            [$detail['details']['tranche']['name'], (float) $detail['details']['tranche']['amount'], $detail['details']['tranche']['planned_release_date']]
        );
        $this->assertEquals(300000, $detail['details']['released']['total']);
        $this->assertSame(['First tranche'], array_column($detail['details']['released']['tranches'], 'name'));
        $this->assertEquals(['budget' => 1000000, 'spent' => 4000, 'remaining' => 996000], $detail['details']['budget']);

        $this->assertCount(1, $detail['documents']);
        $this->assertSame('work-plan.pdf', $detail['documents'][0]['file_name']);
        $this->assertSame('/api/documents/' . $detail['documents'][0]['id'] . '/download', $detail['documents'][0]['download_url']);
        $this->assertTrue($detail['can_decide']);
        $this->assertSame(['requested'], array_column($detail['history'], 'event'));

        // Returned with a reason, then resubmitted: the history shows both.
        $this->actingAs($this->programLeader)->postJson("/api/program/approvals/{$id}/decision", ['decision' => 'returned', 'remarks' => 'Add the budget breakdown.'])->assertOk();
        $this->assertFalse($this->detail($id)['can_decide']);
        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$id}/resubmit", [
            'project_tranche_id' => $this->second->id, 'justification' => 'Breakdown added.',
        ])->assertOk();

        $history = $this->detail($id)['history'];
        $this->assertSame(['requested', 'returned', 'resubmitted'], array_column($history, 'event'));
        $this->assertSame('Add the budget breakdown.', $history[1]['reason']);
        $this->assertSame($this->programLeader->name, $history[1]['by']);
    }

    public function test_a_realignment_shows_each_line_item_before_and_after(): void
    {
        $this->expenseIn('verified', null, false, ['amount' => 1500]);
        $id = $this->create([
            'type' => 'realignment', 'from_lib_category_id' => $this->lineItem->id,
            'to_lib_category_id' => $this->equipment->id, 'amount' => 1000, 'justification' => 'Move savings.',
        ]);

        $details = $this->detail($id)['details'];
        $this->assertSame('realignment', $details['kind']);
        $this->assertFalse($details['applied']);
        $this->assertSame(['Supplies', 'Equipment'], [$details['from']['name'], $details['to']['name']]);
        $this->assertEquals(['allocated' => 500000, 'spent' => 1500, 'pending' => 0, 'available' => 498500], $details['from']['before']);
        $this->assertEquals(['allocated' => 499000, 'spent' => 1500, 'pending' => 0, 'available' => 497500], $details['from']['after']);
        $this->assertEquals(['allocated' => 51000, 'spent' => 0, 'pending' => 0, 'available' => 51000], $details['to']['after']);
        $this->assertSame([], $this->detail($id)['documents']);

        // Once approved the move is in the allocations; before/after stay the same figures.
        $this->actingAs($this->programLeader)->postJson("/api/program/approvals/{$id}/decision", ['decision' => 'approved'])->assertOk();
        $approved = $this->detail($id)['details'];
        $this->assertTrue($approved['applied']);
        $this->assertEquals($details['from']['before'], $approved['from']['before']);
        $this->assertEquals($details['from']['after'], $approved['from']['after']);
        $this->assertEquals($details['to']['after'], $approved['to']['after']);
    }

    public function test_a_liquidation_shows_the_tranche_and_the_verified_expenses_charged_against_it(): void
    {
        AuditLogger::muted(fn () => $this->second->update(['status' => 'released', 'released_at' => '2026-06-01']));
        // Before the first release (2026-01-15) but after the project start (2026-01-01): counted.
        $early = $this->expenseIn('verified', null, true, ['amount' => 300, 'expense_date' => '2026-01-05']);
        $in = $this->expenseIn('verified', null, true, ['amount' => 2000, 'expense_date' => '2026-02-01']);
        $in2 = $this->expenseIn('verified', null, true, ['amount' => 500, 'expense_date' => '2026-05-31']);
        $this->expenseIn('verified', null, true, ['amount' => 9000, 'expense_date' => '2026-06-10']); // second tranche
        $this->expenseIn('endorsed', null, true, ['amount' => 7000, 'expense_date' => '2026-03-01']); // not verified

        $id = $this->create([
            'type' => 'liquidation', 'project_tranche_id' => $this->first->id, 'amount' => 2500,
            'title' => 'First tranche liquidation', 'justification' => 'Receipts for the first tranche.',
            'files' => [$this->uniqueUpload('receipts.pdf')],
        ]);

        $detail = $this->detail($id);
        $details = $detail['details'];
        $this->assertSame('liquidation', $details['kind']);
        $this->assertSame('First tranche', $details['tranche']['name']);
        $this->assertEquals(300000, $details['released_amount']);
        $this->assertSame(['from' => '2026-01-01', 'to' => '2026-05-31', 'starts' => 'project_start'], $details['period']);
        $this->assertSame([$early->id, $in->id, $in2->id], array_column($details['expenses'], 'id'));
        $this->assertEquals(2800, $details['expenses_total']);

        // A later tranche still starts at its own release.
        $second = $this->create([
            'type' => 'liquidation', 'project_tranche_id' => $this->second->id, 'amount' => 9000,
            'title' => 'Second tranche liquidation', 'justification' => 'Receipts for the second tranche.',
            'files' => [$this->uniqueUpload('receipts-2.pdf')],
        ]);
        $later = $this->detail($second)['details'];
        $this->assertSame(['from' => '2026-06-01', 'to' => null, 'starts' => 'release'], $later['period']);
        $this->assertEquals(9000, $later['expenses_total']);
        $this->assertSame('Supplies', $details['expenses'][0]['line_item']);
        $this->assertSame(['receipts.pdf'], array_column($detail['documents'], 'file_name'));
    }

    public function test_an_escalated_payroll_shows_the_batch_and_its_files(): void
    {
        $id = AuditLogger::muted(function () {
            $batch = PayrollBatch::create([
                'project_id' => $this->project->id, 'pay_year' => 2026, 'pay_month' => 10,
                'prepared_by' => $this->assistant->id, 'workflow_status' => 'submitted',
            ]);
            $this->payrollFileFor($batch, $this->assistant);

            return ApprovalRequest::create([
                'project_id' => $this->project->id, 'type' => 'payroll', 'title' => 'Payroll October 2026: approve',
                'justification' => 'The Project Leader is paid in this batch.', 'amount' => 0,
                'payload' => ['payroll_batch_id' => $batch->id, 'step' => 'approve', 'month' => '2026-10'],
                'requested_by' => $this->projectLeader->id, 'status' => 'pending',
            ])->id;
        });

        $detail = $this->detail($id);
        $this->assertSame(['payroll', 'October 2026', 'Approve'], [$detail['details']['kind'], $detail['details']['month'], $detail['details']['step']]);
        $this->assertSame(['payroll.pdf'], array_column($detail['documents'], 'file_name'));
        $this->assertTrue($detail['can_decide']);
    }

    public function test_only_people_who_can_see_the_project_get_the_details_and_files(): void
    {
        $id = $this->create([
            'type' => 'fund_release', 'project_tranche_id' => $this->second->id, 'justification' => 'Fieldwork.',
            'files' => [$this->uniqueUpload('work-plan.pdf')],
        ]);
        $documentId = ApprovalRequest::find($id)->documents()->value('id');

        // Finance/Admin of another project: 403 on the details, the list of files and each file.
        $this->actingAs($this->outsider);
        $this->getJson("/api/approvals/{$id}")->assertForbidden();
        $this->getJson("/api/approvals/{$id}/documents")->assertForbidden();
        $this->getJson("/api/documents/{$documentId}/download")->assertForbidden();
        $this->getJson("/api/documents/{$documentId}/download?inline=1")->assertForbidden();

        // A Project Leader of another project too.
        $otherLeader = $this->makePerson('project-leader');
        $this->assignTo($otherLeader, $this->otherProject, 'project-leader');
        $this->actingAs($otherLeader)->getJson("/api/approvals/{$id}")->assertForbidden();
        $this->actingAs($otherLeader)->getJson("/api/documents/{$documentId}/download")->assertForbidden();

        // Members of the project may look, but only the Program Leader decides.
        $member = $this->actingAs($this->researcher)->getJson("/api/approvals/{$id}")->assertOk();
        $this->assertFalse($member->json('approval.can_decide'));
        $this->actingAs($this->researcher)->get("/api/documents/{$documentId}/download")->assertOk();

        $own = $this->actingAs($this->projectLeader)->getJson("/api/approvals/{$id}")->assertOk();
        $this->assertFalse($own->json('approval.can_decide'));
        $this->assertTrue($this->detail($id)['can_decide']);
        $this->actingAs($this->programLeader)->get("/api/documents/{$documentId}/download?inline=1")->assertOk();
    }

    private function create(array $fields): int
    {
        return $this->actingAs($this->projectLeader)
            ->post("/api/projects/{$this->project->id}/budget-requests", $fields, ['Accept' => 'application/json'])
            ->assertCreated()->json('budget_request.id');
    }

    private function detail(int $id): array
    {
        return $this->actingAs($this->programLeader)->getJson("/api/approvals/{$id}")->assertOk()->json('approval');
    }
}
