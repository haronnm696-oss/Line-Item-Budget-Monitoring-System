<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\LibCategory;
use App\Models\PayrollBatch;
use App\Models\ProjectUser;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PurchaseRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * One Project Leader per project: nothing waits for "another Project
 * Leader". Each item reaches its reviewer, and the maker never approves.
 */
class LeaderStepRoutingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private LibCategory $ps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        config(['documents.disk' => 'local']);
        Storage::fake('local');
        $this->ps = AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'PS',
            'line_item_name' => 'Salaries of project staff', 'allocated_amount' => 900000,
        ]));
    }

    // ------------------------------------------------------------ expenses

    public function test_an_expense_recorded_by_the_project_leader_goes_straight_to_finance(): void
    {
        $expense = $this->expenseIn('draft', $this->projectLeader);

        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$expense->id}/submit")
            ->assertOk()->assertJsonPath('expense.workflow_status', 'endorsed');
        $this->assertNull($expense->fresh()->pl_reviewed_by);
        $this->assertSame(1, $this->finance->notifications()->count());

        // The maker never approves or verifies it.
        $this->postJson("/api/expenses/{$expense->id}/endorse")->assertForbidden();
        $this->postJson("/api/expenses/{$expense->id}/verify")->assertForbidden();

        $this->actingAs($this->finance)->postJson("/api/expenses/{$expense->id}/verify")
            ->assertOk()->assertJsonPath('expense.workflow_status', 'verified');
    }

    public function test_an_expense_by_a_leader_who_also_holds_an_encoder_role_skips_the_leader_step(): void
    {
        // Older data: the Project Leader also has a Research Assistant row.
        AuditLogger::muted(fn () => $this->assignTo($this->projectLeader, $this->project, 'research-assistant'));
        $expense = $this->expenseIn('draft', $this->projectLeader);

        $this->actingAs($this->projectLeader)->postJson("/api/expenses/{$expense->id}/submit")
            ->assertOk()->assertJsonPath('expense.workflow_status', 'endorsed')
            ->assertJsonPath('expense.workflow_label', 'Waiting for Finance/Admin verification');

        // Not in their own "waiting for you" queue.
        $this->getJson("/api/projects/{$this->project->id}/review-queue?tab=waiting&type=expense")
            ->assertOk()->assertJsonCount(0, 'items');

        $this->actingAs($this->finance)->postJson("/api/expenses/{$expense->id}/verify")->assertOk();
    }

    public function test_a_submitted_expense_whose_recorder_becomes_the_project_leader_moves_on_to_finance(): void
    {
        $expense = $this->expenseIn('submitted', $this->assistant);
        // The old Project Leader leaves; the assistant becomes the Project Leader.
        AuditLogger::muted(fn () => ProjectUser::where('user_id', $this->projectLeader->id)->delete());

        $this->actingAs($this->programLeader)
            ->patchJson("/api/program/users/{$this->assistant->id}/role", ['role' => 'project-leader'])
            ->assertOk();

        $expense->refresh();
        $this->assertSame('endorsed', $expense->workflow_status);
        $this->assertNull($expense->pl_reviewed_by);
        $this->assertDatabaseHas('audit_trails', ['action' => 'rerouted', 'model_type' => 'Expense', 'model_id' => $expense->id]);

        // The maker (now the Project Leader) cannot approve it; Finance/Admin verifies it.
        $this->actingAs($this->assistant->fresh())->postJson("/api/expenses/{$expense->id}/endorse")->assertForbidden();
        $this->actingAs($this->finance)->postJson("/api/expenses/{$expense->id}/verify")->assertOk();
    }

    // ------------------------------------------------------------ purchase requests

    public function test_a_purchase_request_made_by_the_project_leader_skips_the_leader_step(): void
    {
        AuditLogger::muted(fn () => $this->assignTo($this->projectLeader, $this->project, 'research-assistant'));
        $request = $this->purchaseRequestBy($this->projectLeader, 'draft');

        $this->actingAs($this->projectLeader)->postJson("/api/purchase-requests/{$request->id}/submit")->assertOk();

        $request->refresh();
        $this->assertSame('endorsed', $request->workflow_status);
        $this->assertNull($request->pl_reviewed_by);
        $this->assertSame(PurchaseRequestService::NO_LEADER_STEP, PurchaseRequestService::label($request));
        $this->assertSame(1, $this->finance->notifications()->count());

        // The maker never approves it; the expense recorded for it still goes to Finance/Admin.
        $this->postJson("/api/purchase-requests/{$request->id}/endorse")->assertForbidden();
    }

    public function test_the_reroute_command_moves_existing_stuck_requests_and_expenses(): void
    {
        AuditLogger::muted(fn () => $this->assignTo($this->projectLeader, $this->project, 'research-assistant'));
        $request = $this->purchaseRequestBy($this->projectLeader, 'submitted');
        $expense = $this->expenseIn('submitted', $this->projectLeader);
        $other = $this->expenseIn('submitted', $this->assistant); // a normal one: stays with the Project Leader

        $this->artisan('workflow:reroute-leader-steps')
            ->expectsOutput('Dry run. Expenses: 1, purchase requests: 1. Payroll batches are checked only with --apply.')
            ->assertSuccessful();
        $this->assertSame('submitted', $expense->fresh()->workflow_status);

        $this->artisan('workflow:reroute-leader-steps', ['--apply' => true, '--as' => $this->programLeader->email])
            ->assertSuccessful();

        $this->assertSame('endorsed', $expense->fresh()->workflow_status);
        $this->assertSame('endorsed', $request->fresh()->workflow_status);
        $this->assertSame('submitted', $other->fresh()->workflow_status);
        $this->assertSame(2, AuditTrail::where('action', 'rerouted')->count());
    }

    // ------------------------------------------------------------ payroll

    public function test_a_payroll_that_pays_the_project_leader_goes_to_the_program_leader_then_finance(): void
    {
        $this->rate($this->researcher, 30000);
        $this->rate($this->projectLeader, 45000);
        $batch = $this->submittedBatch();

        $approval = $batch->pendingEscalation();
        $this->assertNotNull($approval);
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertForbidden();

        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved'])
            ->assertOk();
        $this->assertSame('endorsed', $batch->fresh()->workflow_status);

        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk();
        $this->assertSame('verified', $batch->fresh()->workflow_status);
    }

    public function test_a_payroll_prepared_by_the_project_leader_goes_to_the_program_leader(): void
    {
        // Older data: the Project Leader also encodes, and prepares the batch (not paid in it).
        AuditLogger::muted(fn () => $this->assignTo($this->projectLeader, $this->project, 'research-assistant'));
        $this->rate($this->researcher, 30000);
        $batch = $this->submittedBatch('2026-11', $this->projectLeader);

        $approval = $batch->pendingEscalation();
        $this->assertNotNull($approval, 'escalated, not waiting for "another Project Leader"');
        $this->assertStringContainsString('prepared by the Project Leader', $approval->justification);
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertForbidden();

        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved'])
            ->assertOk();
        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk();
        $this->assertSame('verified', $batch->fresh()->workflow_status);
    }

    public function test_a_normal_payroll_still_goes_to_the_project_leader(): void
    {
        $this->rate($this->researcher, 30000);
        $batch = $this->submittedBatch();

        $this->assertNull($batch->pendingEscalation());
        $this->actingAs($this->projectLeader)->postJson("/api/payroll-batches/{$batch->id}/endorse")->assertOk();
        $this->actingAs($this->finance)->postJson("/api/payroll-batches/{$batch->id}/verify")->assertOk();
    }

    // ------------------------------------------------------------ helpers

    private function purchaseRequestBy(User $user, string $stage): PurchaseRequest
    {
        return AuditLogger::muted(function () use ($user, $stage) {
            $request = PurchaseRequest::create([
                'project_id' => $this->project->id, 'lib_category_id' => $this->lineItem->id, 'requested_by' => $user->id,
                'description' => 'Microscope slides', 'quantity' => 10, 'estimated_amount' => 3500,
                'workflow_status' => $stage, 'submitted_at' => $stage === 'draft' ? null : now(),
            ]);
            $request->documents()->create([
                'uploaded_by' => $user->id, 'document_type' => 'quotation', 'file_name' => 'quote.pdf',
                'file_path' => 'documents/purchase-requests/' . $request->id . '/quote.pdf', 'disk' => 'local',
                'size' => 1024, 'mime' => 'application/pdf', 'uploaded_at' => now(),
            ]);

            return $request;
        });
    }

    private function rate(User $user, float $monthly): void
    {
        $this->setHonorarium($user, $monthly);
    }

    private function submittedBatch(string $month = '2026-11', ?User $maker = null): PayrollBatch
    {
        $maker ??= $this->assistant;
        $this->actingAs($maker)
            ->postJson("/api/projects/{$this->project->id}/payroll", ['month' => $month, 'lib_category_id' => $this->ps->id])
            ->assertCreated();
        [$year, $number] = array_map('intval', explode('-', $month));
        $batch = PayrollBatch::where('project_id', $this->project->id)->where('pay_year', $year)->where('pay_month', $number)->firstOrFail();
        $this->payrollFileFor($batch, $maker);
        $this->postJson("/api/payroll-batches/{$batch->id}/submit")->assertOk();

        return $batch->fresh();
    }
}
