<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\ProjectTranche;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * The Program Leader approves or returns a budget request. A returned
 * request goes back to its maker (the Project Leader), who edits and
 * resubmits it or withdraws it. Legacy "rejected" rows behave as returned.
 */
class BudgetRequestReturnTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private ProjectTranche $tranche;
    private LibCategory $equipment;

    protected function setUp(): void
    {
        parent::setUp();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->buildWorkflowFixtures();
        [$this->tranche, $this->equipment] = AuditLogger::muted(fn () => [
            ProjectTranche::create([
                'project_id' => $this->project->id, 'sequence' => 2, 'name' => 'Second tranche',
                'amount' => 250000, 'status' => 'due', 'planned_release_date' => '2026-12-01',
            ]),
            LibCategory::create([
                'project_id' => $this->project->id, 'category_type' => 'MOOE',
                'line_item_name' => 'Equipment', 'allocated_amount' => 50000,
            ]),
        ]);
    }

    public function test_return_requires_a_reason(): void
    {
        $id = $this->fundRelease();
        $this->actingAs($this->programLeader);

        foreach (['returned', 'rejected'] as $decision) {
            $this->postJson($this->decisionUrl($id), ['decision' => $decision])
                ->assertStatus(422)->assertJsonPath('errors.remarks.0', 'A reason is required to return a request.');
            $this->postJson($this->decisionUrl($id), ['decision' => $decision, 'remarks' => '   '])
                ->assertStatus(422)->assertJsonValidationErrors(['remarks']);
        }
        $this->assertSame('pending', ApprovalRequest::find($id)->status);

        $audit = AuditTrail::count();
        $this->postJson($this->decisionUrl($id), ['decision' => 'returned', 'remarks' => 'Add the work plan.'])
            ->assertOk()->assertJsonPath('approval.status', 'returned');
        $this->assertSame($audit + 1, AuditTrail::count());
        $entry = AuditTrail::latest('id')->first();
        $this->assertSame(['returned', 'Add the work plan.'], [$entry->action, $entry->reason]);

        // The Project Leader sees Returned with the reason, and may edit or withdraw it.
        $row = $this->myRow($id);
        $this->assertSame(['returned', 'Returned', 'Add the work plan.'], [$row['status'], $row['status_label'], $row['review_notes']]);
        $this->assertTrue($row['can_resubmit']);
        $this->assertTrue($row['can_withdraw']);
        $this->assertSame($this->tranche->id, $row['edit']['project_tranche_id']);
        $this->assertSame('fund_release', $row['edit']['type']);

        $note = $this->projectLeader->notifications()->first();
        $this->assertSame(['returned', 'Add the work plan.'], [$note->data['event'], $note->data['reason']]);
        $this->assertSame("/my-requests?project={$this->project->id}&request={$id}", $note->data['link']);
    }

    public function test_only_the_maker_can_edit_resubmit_or_withdraw(): void
    {
        $id = $this->returnedFundRelease();
        $otherLeader = $this->member('project-leader');
        $body = ['project_tranche_id' => $this->tranche->id, 'justification' => 'Work plan attached.'];

        foreach ([$otherLeader, $this->programLeader, $this->finance] as $user) {
            $this->actingAs($user)->postJson("/api/budget-requests/{$id}/resubmit", $body)->assertForbidden();
            $this->actingAs($user)->postJson("/api/budget-requests/{$id}/withdraw")->assertForbidden();
        }
        // Not assigned to the project at all.
        $this->actingAs($this->outsider)->postJson("/api/budget-requests/{$id}/resubmit", $body)->assertForbidden();

        // Another Project Leader sees the row but no edit values or actions.
        $row = $this->myRow($id, $otherLeader);
        $this->assertSame('returned', $row['status']);
        $this->assertFalse($row['can_resubmit']);
        $this->assertFalse($row['can_withdraw']);
        $this->assertNull($row['edit']);

        $this->assertTrue(ApprovalRequest::find($id)->isReturned());

        // Only a returned request: pending and approved ones cannot be resubmitted or withdrawn.
        $pending = $this->realignment();
        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$pending}/resubmit", [
            'from_lib_category_id' => $this->lineItem->id, 'to_lib_category_id' => $this->equipment->id, 'amount' => 10, 'justification' => 'x',
        ])->assertStatus(422)->assertJsonPath('errors.status.0', 'This budget request cannot be resubmitted while it is pending.');
        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$pending}/withdraw")
            ->assertStatus(422)->assertJsonPath('errors.status.0', 'This budget request cannot be withdrawn while it is pending.');
    }

    public function test_resubmit_sends_it_back_once_with_one_audit_entry_and_one_notification(): void
    {
        $id = $this->returnedFundRelease();
        DB::table('notifications')->delete();
        $audit = AuditTrail::where('model_type', 'ApprovalRequest')->count();

        $this->actingAs($this->projectLeader)
            ->post("/api/budget-requests/{$id}/resubmit", [
                'project_tranche_id' => $this->tranche->id,
                'justification' => 'Work plan attached as requested.',
                'files' => [$this->uniqueUpload('work-plan.pdf')],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('budget_request.status', 'pending')
            ->assertJsonPath('budget_request.justification', 'Work plan attached as requested.')
            ->assertJsonPath('budget_request.last_return_reason', 'Add the work plan.')
            ->assertJsonPath('budget_request.review_notes', null)
            ->assertJsonPath('budget_request.documents_count', 1)
            ->assertJsonPath('budget_request.can_resubmit', false);

        // One audit entry on the request ("resubmitted"); the upload has its own Document entry.
        $this->assertSame($audit + 1, AuditTrail::where('model_type', 'ApprovalRequest')->count());
        $this->assertSame('resubmitted', AuditTrail::where('model_type', 'ApprovalRequest')->latest('id')->value('action'));

        // One notification, to the Program Leader.
        $this->assertSame(1, DB::table('notifications')->count());
        $note = $this->programLeader->notifications()->first();
        $this->assertSame('resubmitted', $note->data['event']);
        $this->assertSame($id, $note->data['approval_id']);

        // It is Pending on Approvals again and can be decided.
        $pending = $this->actingAs($this->programLeader)->getJson('/api/program/approvals?status=pending')->json('approvals');
        $row = collect($pending)->firstWhere('id', $id);
        $this->assertSame('Add the work plan.', $row['last_return_reason']);
        $this->postJson($this->decisionUrl($id), ['decision' => 'approved'])->assertOk();
        $this->assertSame('released', $this->tranche->fresh()->status);

        // A second resubmit is refused (it is no longer returned).
        $this->actingAs($this->projectLeader)
            ->postJson("/api/budget-requests/{$id}/resubmit", ['project_tranche_id' => $this->tranche->id, 'justification' => 'Again'])
            ->assertStatus(422);
    }

    public function test_resubmit_checks_the_fields_again_and_keeps_or_replaces_documents(): void
    {
        // Realignment: still limited by the source line item.
        $id = $this->realignment();
        $this->returnIt($id, 'Lower the amount.');
        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$id}/resubmit", [
            'from_lib_category_id' => $this->lineItem->id, 'to_lib_category_id' => $this->equipment->id,
            'amount' => 99999999, 'justification' => 'More.',
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$id}/resubmit", [
            'from_lib_category_id' => $this->lineItem->id, 'to_lib_category_id' => $this->equipment->id,
            'amount' => 500, 'justification' => 'Lowered.',
        ])->assertOk()->assertJsonPath('budget_request.amount', 500)->assertJsonPath('budget_request.payload.amount', 500);

        // Liquidation: needs at least one document after removals.
        $liq = $this->actingAs($this->projectLeader)->post("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'liquidation', 'amount' => 1200, 'justification' => 'Receipts for October.',
            'files' => [$this->uniqueUpload('october.pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('budget_request.id');
        $this->returnIt($liq, 'Wrong receipt.');
        $docId = ApprovalRequest::find($liq)->documents()->value('id');

        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$liq}/resubmit", [
            'amount' => 1200, 'justification' => 'Fixed.', 'remove_document_ids' => [$docId],
        ])->assertStatus(422)->assertJsonValidationErrors(['files']);
        $this->assertTrue(ApprovalRequest::find($liq)->isReturned());

        $this->actingAs($this->projectLeader)->post("/api/budget-requests/{$liq}/resubmit", [
            'amount' => 1300, 'justification' => 'Fixed.', 'remove_document_ids' => [$docId],
            'files' => [$this->uniqueUpload('october-fixed.pdf')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('budget_request.amount', 1300);
        $docs = ApprovalRequest::find($liq)->documents()->pluck('file_name')->all();
        $this->assertSame(['october-fixed.pdf'], $docs);
    }

    public function test_withdrawn_frees_the_tranche_and_cannot_be_resubmitted(): void
    {
        $id = $this->returnedFundRelease();
        $audit = AuditTrail::count();

        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$id}/withdraw")
            ->assertOk()
            ->assertJsonPath('budget_request.status', 'withdrawn')
            ->assertJsonPath('budget_request.status_label', 'Withdrawn')
            ->assertJsonPath('budget_request.can_resubmit', false)
            ->assertJsonPath('budget_request.can_withdraw', false);
        $this->assertSame($audit + 1, AuditTrail::count());
        $this->assertSame('withdrawn', AuditTrail::latest('id')->value('action'));

        $this->actingAs($this->projectLeader)
            ->postJson("/api/budget-requests/{$id}/resubmit", ['project_tranche_id' => $this->tranche->id, 'justification' => 'x'])
            ->assertStatus(422)->assertJsonPath('errors.status.0', 'This budget request cannot be resubmitted while it is withdrawn.');
        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$id}/withdraw")->assertStatus(422);

        // The tranche is free: a new fund release request is accepted.
        $this->fundRelease();
        $this->assertSame(1, ApprovalRequest::where('project_tranche_id', $this->tranche->id)->where('status', 'pending')->count());
    }

    public function test_a_returned_fund_release_frees_its_tranche_and_resubmitting_respects_a_newer_request(): void
    {
        $old = $this->returnedFundRelease();
        $new = $this->fundRelease(); // allowed: the returned one no longer holds the tranche

        $this->actingAs($this->projectLeader)
            ->postJson("/api/budget-requests/{$old}/resubmit", ['project_tranche_id' => $this->tranche->id, 'justification' => 'Again.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.project_tranche_id.0', 'A fund release request for this tranche is already pending.');

        $this->actingAs($this->programLeader)->postJson($this->decisionUrl($new), ['decision' => 'approved'])->assertOk();
        $this->actingAs($this->projectLeader)
            ->postJson("/api/budget-requests/{$old}/resubmit", ['project_tranche_id' => $this->tranche->id, 'justification' => 'Again.'])
            ->assertStatus(422)
            ->assertJsonPath('errors.project_tranche_id.0', 'This tranche has already been released.');
    }

    public function test_a_legacy_rejected_row_shows_as_returned_and_can_be_resubmitted(): void
    {
        $id = AuditLogger::muted(fn () => ApprovalRequest::create([
            'project_id' => $this->project->id, 'type' => 'disbursement', 'title' => 'Fund release: Second tranche',
            'amount' => 250000, 'project_tranche_id' => $this->tranche->id, 'requested_by' => $this->projectLeader->id,
            'justification' => 'Old request.', 'status' => 'rejected', 'review_notes' => 'Add the work plan.',
            'reviewed_by' => $this->programLeader->id, 'reviewed_at' => now(),
        ])->id);
        // Legacy rows may have no justification.
        DB::table('approval_requests')->where('id', $id)->update(['justification' => null]);

        $row = $this->myRow($id);
        $this->assertSame('returned', $row['status']);
        $this->assertTrue($row['can_resubmit']);
        $this->assertNull($row['edit']['justification']);

        $counts = $this->actingAs($this->programLeader)->getJson('/api/program/approvals?status=returned')->assertOk();
        $counts->assertJsonPath('counts.returned', 1)->assertJsonPath('approvals.0.status', 'returned');
        // Older links with ?status=rejected open the Returned tab.
        $this->getJson('/api/program/approvals?status=rejected')->assertOk()->assertJsonPath('approvals.0.id', $id);

        // A justification is needed to resubmit.
        $this->actingAs($this->projectLeader)
            ->postJson("/api/budget-requests/{$id}/resubmit", ['project_tranche_id' => $this->tranche->id])
            ->assertStatus(422)->assertJsonValidationErrors(['justification']);
        $this->actingAs($this->projectLeader)
            ->postJson("/api/budget-requests/{$id}/resubmit", ['project_tranche_id' => $this->tranche->id, 'justification' => 'Work plan attached.'])
            ->assertOk()->assertJsonPath('budget_request.status', 'pending');
    }

    public function test_approvals_and_my_requests_counts_stay_consistent(): void
    {
        $a = $this->fundRelease();
        $b = $this->realignment();
        $c = $this->actingAs($this->projectLeader)->post("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'liquidation', 'amount' => 900, 'justification' => 'Receipts.', 'files' => [$this->uniqueUpload('r.pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('budget_request.id');
        $this->assertConsistent(['pending' => 3, 'approved' => 0, 'returned' => 0, 'withdrawn' => 0]);

        $this->returnIt($a, 'Add the work plan.');
        $this->returnIt($b, 'Too much.');
        $this->assertConsistent(['pending' => 1, 'approved' => 0, 'returned' => 2, 'withdrawn' => 0]);
        // The Project Leader's "My requests" badge counts the two decisions.
        $this->actingAs($this->projectLeader)->getJson("/api/projects/{$this->project->id}/leader-badges")
            ->assertOk()->assertJsonPath('requests', 2);

        $this->actingAs($this->projectLeader)
            ->postJson("/api/budget-requests/{$a}/resubmit", ['project_tranche_id' => $this->tranche->id, 'justification' => 'Plan attached.'])
            ->assertOk();
        $this->actingAs($this->projectLeader)->postJson("/api/budget-requests/{$b}/withdraw")->assertOk();
        $this->assertConsistent(['pending' => 2, 'approved' => 0, 'returned' => 0, 'withdrawn' => 1]);

        $this->actingAs($this->programLeader)->postJson($this->decisionUrl($a), ['decision' => 'approved'])->assertOk();
        $this->actingAs($this->programLeader)->postJson($this->decisionUrl($c), ['decision' => 'returned', 'remarks' => 'Unclear.'])->assertOk();
        $this->assertConsistent(['pending' => 0, 'approved' => 1, 'returned' => 1, 'withdrawn' => 1]);
    }

    // ---------------------------------------------------------------- helpers

    /** Approvals tab counts (this project) = My requests rows per status = each tab's rows. */
    private function assertConsistent(array $expected): void
    {
        $mine = collect($this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/budget-requests")->assertOk()->json('budget_requests'))
            ->countBy('status')->all();
        $overview = collect($this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/leader-overview")->assertOk()->json('requests'))
            ->countBy('status')->all();

        $this->actingAs($this->programLeader);
        foreach ($expected as $status => $count) {
            $response = $this->getJson("/api/program/approvals?status={$status}&project={$this->project->id}")->assertOk();
            $this->assertSame($expected, $response->json('counts'), "counts on the {$status} tab");
            $this->assertCount($count, $response->json('approvals'), "{$status} rows");
            $this->assertSame($count, $mine[$status] ?? 0, "My requests {$status}");
            $this->assertSame($count, $overview[$status] ?? 0, "Overview {$status}");
        }
    }

    private function fundRelease(): int
    {
        return $this->actingAs($this->projectLeader)
            ->postJson("/api/projects/{$this->project->id}/budget-requests", [
                'type' => 'fund_release', 'project_tranche_id' => $this->tranche->id, 'justification' => 'Fieldwork starts in December.',
            ])->assertCreated()->json('budget_request.id');
    }

    private function realignment(): int
    {
        return $this->actingAs($this->projectLeader)
            ->postJson("/api/projects/{$this->project->id}/budget-requests", [
                'type' => 'realignment', 'from_lib_category_id' => $this->lineItem->id,
                'to_lib_category_id' => $this->equipment->id, 'amount' => 1000, 'justification' => 'Move savings.',
            ])->assertCreated()->json('budget_request.id');
    }

    private function returnedFundRelease(): int
    {
        $id = $this->fundRelease();
        $this->returnIt($id, 'Add the work plan.');

        return $id;
    }

    private function returnIt(int $id, string $reason): void
    {
        $this->actingAs($this->programLeader)
            ->postJson($this->decisionUrl($id), ['decision' => 'returned', 'remarks' => $reason])
            ->assertOk();
    }

    private function decisionUrl(int $id): string
    {
        return "/api/program/approvals/{$id}/decision";
    }

    private function myRow(int $id, ?User $as = null): array
    {
        return collect($this->actingAs($as ?? $this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/budget-requests")->assertOk()->json('budget_requests'))
            ->firstWhere('id', $id);
    }
}
