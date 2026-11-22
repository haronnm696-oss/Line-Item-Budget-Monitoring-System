<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\PayrollBatch;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Researchers never see personnel pay: no payroll register (JSON or CSV), no
 * payroll rows, no payroll files, no personnel honoraria or bank accounts.
 * They still see payroll as totals (budget summary, transactions, Form B).
 */
class ResearcherPayPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    private PayrollBatch $batch;

    /** The person paid in the batch (not the preparer or verifier). */
    private User $payee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        Storage::fake('local');

        $this->payee = AuditLogger::muted(fn () => $this->member('research-assistant'));
        $this->batch = AuditLogger::muted(function () {
            $ps = LibCategory::create([
                'project_id' => $this->project->id, 'category_type' => 'PS',
                'line_item_name' => 'Honoraria', 'allocated_amount' => 500000,
            ]);
            $batch = PayrollBatch::create([
                'project_id' => $this->project->id, 'pay_year' => 2026, 'pay_month' => 10,
                'prepared_by' => $this->assistant->id, 'workflow_status' => 'verified',
                'verified_by' => $this->finance->id, 'verified_at' => now(),
            ]);
            Payroll::create([
                'project_id' => $this->project->id, 'payroll_batch_id' => $batch->id, 'lib_category_id' => $ps->id,
                'personnel_id' => $this->payee->id, 'computed_by' => $this->assistant->id,
                'pay_year' => 2026, 'pay_month' => 10, 'basic_rate' => 18000, 'months' => 1,
                'computation' => [['honorarium' => 18000, 'months' => 1]], 'allowance' => 0,
                'gross_pay' => 18000, 'total_deductions' => 0, 'net_pay' => 18000,
                'status' => 'approved', 'workflow_status' => 'verified',
            ]);

            return $batch;
        });
    }

    private function others(): array
    {
        return [$this->programLeader, $this->projectLeader, $this->finance, $this->assistant];
    }

    public function test_a_researcher_gets_403_on_the_payroll_register_json_and_csv(): void
    {
        $json = "/api/projects/{$this->project->id}/payroll-register";
        $csv = "/api/projects/{$this->project->id}/reports/payroll-register";

        $this->actingAs($this->researcher)->getJson($json)->assertForbidden();
        $this->actingAs($this->researcher)->getJson("{$json}?month=2026-10")->assertForbidden();
        $this->actingAs($this->researcher)->get($csv)->assertForbidden();
        $this->actingAs($this->researcher)->get("{$csv}?period=year")->assertForbidden();

        foreach ($this->others() as $user) {
            $this->actingAs($user)->getJson($json)->assertOk();
            $this->actingAs($user)->get($csv)->assertOk();
        }
    }

    public function test_a_researcher_who_is_also_a_research_assistant_keeps_the_register(): void
    {
        $this->assignTo($this->researcher, $this->project, 'research-assistant');

        $this->actingAs($this->researcher)->getJson("/api/projects/{$this->project->id}/payroll-register")->assertOk();
    }

    public function test_a_researcher_sees_no_payroll_rows_personnel_or_payroll_files(): void
    {
        $file = $this->payrollFileFor($this->batch, $this->assistant);
        Storage::disk('local')->put($file->file_path, 'PAYROLL');

        $this->actingAs($this->researcher);
        $this->getJson("/api/projects/{$this->project->id}/payroll?month=2026-10")->assertForbidden();
        $this->getJson("/api/projects/{$this->project->id}/personnel")->assertForbidden();
        $this->getJson("/api/projects/{$this->project->id}/payroll-batches")->assertForbidden();
        $this->getJson("/api/payroll-batches/{$this->batch->id}/documents")->assertForbidden();
        $this->get("/api/documents/{$file->id}/download")->assertForbidden();
        $this->get("/api/documents/{$file->id}/download?inline=1")->assertForbidden();

        foreach ($this->others() as $user) {
            $this->actingAs($user)->getJson("/api/projects/{$this->project->id}/payroll?month=2026-10")->assertOk();
            $this->actingAs($user)->getJson("/api/payroll-batches/{$this->batch->id}/documents")->assertOk();
            $this->actingAs($user)->get("/api/documents/{$file->id}/download")->assertOk();
        }
    }

    public function test_a_payroll_approval_lists_no_payroll_files_for_a_researcher(): void
    {
        $this->payrollFileFor($this->batch, $this->assistant);
        $id = AuditLogger::muted(fn () => ApprovalRequest::create([
            'project_id' => $this->project->id, 'type' => 'payroll', 'title' => 'Payroll October 2026: approve',
            'justification' => 'The Project Leader is paid in this batch.', 'amount' => 0,
            'payload' => ['payroll_batch_id' => $this->batch->id, 'step' => 'approve', 'month' => '2026-10'],
            'requested_by' => $this->projectLeader->id, 'status' => 'pending',
        ])->id);

        $member = $this->actingAs($this->researcher)->getJson("/api/approvals/{$id}")->assertOk();
        $this->assertSame([], $member->json('approval.documents'));
        // Totals only, never per person.
        $this->assertSame(1, $member->json('approval.details.people'));

        $leader = $this->actingAs($this->programLeader)->getJson("/api/approvals/{$id}")->assertOk();
        $this->assertCount(1, $leader->json('approval.documents'));
    }

    public function test_the_researchers_screens_show_payroll_only_as_totals(): void
    {
        $this->actingAs($this->researcher);
        $screens = [
            $this->getJson("/api/projects/{$this->project->id}/budget-summary")->assertOk(),
            $this->getJson("/api/projects/{$this->project->id}/transactions")->assertOk(),
            $this->getJson("/api/projects/{$this->project->id}")->assertOk(),
            $this->getJson("/api/projects/{$this->project->id}/form-b")->assertOk(),
        ];

        foreach ($screens as $response) {
            $body = $response->getContent();
            foreach (['monthly_honorarium', 'bank_account', 'basic_rate', 'monthly_rate', 'net_pay', $this->payee->name] as $needle) {
                $this->assertStringNotContainsString($needle, $body);
            }
        }

        // The verified payroll still counts in Spent (as one row per batch).
        $payroll = collect($screens[1]->json('transactions'))->firstWhere('kind', 'payroll');
        $this->assertEquals(18000, $payroll['amount']);
        $this->assertNull($payroll['payee']);
    }
}
