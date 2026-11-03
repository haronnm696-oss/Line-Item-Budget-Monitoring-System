<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Liquidation;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_overview_is_forbidden_for_non_program_leaders(): void
    {
        $this->actingAs($this->userWithRole('project-leader'))
            ->getJson('/api/program/overview')
            ->assertForbidden();

        $this->actingAs($this->userWithRole('program-leader'))
            ->getJson('/api/program/overview')
            ->assertOk()
            ->assertJsonStructure([
                'meta', 'kpis', 'projects', 'approvals', 'forecast',
                'funders', 'funding_sources', 'deadlines', 'exceptions',
            ]);
    }

    public function test_health_rules_and_funder_filter_are_applied(): void
    {
        $programLeader = $this->userWithRole('program-leader');
        $most = FundingAgency::create(['name' => 'MOST-BARMM', 'acronym' => 'MOST-BARMM']);
        $dost = FundingAgency::create(['name' => 'DOST', 'acronym' => 'DOST']);

        $atRisk = $this->project($most, [
            'code' => 'MOST-RD-2026-001',
            'title' => 'Enhancing Urban Mobility',
            'total_approved_budget' => 3178376,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $this->verifiedExpense($atRisk, 1360000);
        ProjectTranche::create([
            'project_id' => $atRisk->id,
            'sequence' => 1,
            'name' => 'Tranche 1',
            'amount' => 1000000,
            'status' => 'released',
            'planned_release_date' => '2026-01-15',
            'released_at' => '2026-01-20',
        ]);
        ProjectTranche::create([
            'project_id' => $atRisk->id,
            'sequence' => 2,
            'name' => 'Tranche 2',
            'amount' => 1000000,
            'status' => 'due',
            'planned_release_date' => '2026-09-30',
        ]);

        $healthy = $this->project($dost, [
            'code' => 'RP-2026-009',
            'title' => 'Halal Goat Value Chain',
            'total_approved_budget' => 1870000,
            'start_date' => '2026-03-01',
            'end_date' => '2027-02-28',
        ]);
        $this->verifiedExpense($healthy, 1514700);

        $response = $this->actingAs($programLeader)
            ->getJson('/api/program/overview')
            ->assertOk();

        $rows = collect($response->json('projects'))->keyBy('code');
        $this->assertSame('At risk', $rows['MOST-RD-2026-001']['health']);
        $this->assertSame('Tranche release overdue', $rows['MOST-RD-2026-001']['health_reason']);
        $this->assertSame('Healthy', $rows['RP-2026-009']['health']);
        $this->assertSame(1, $response->json('kpis.health.at_risk'));
        $this->assertSame(1, $response->json('kpis.health.healthy'));

        $filtered = $this->actingAs($programLeader)
            ->getJson('/api/program/overview?funder=' . $dost->id)
            ->assertOk();

        $this->assertCount(1, $filtered->json('projects'));
        $this->assertSame('RP-2026-009', $filtered->json('projects.0.code'));
        $this->assertCount(1, $filtered->json('funding_sources'));
    }

    public function test_decision_is_forbidden_for_non_program_leaders(): void
    {
        $approval = $this->approvalFor($this->project($this->funder(), []), 'budget_realignment');

        $this->actingAs($this->userWithRole('project-leader'))
            ->postJson("/api/program/approvals/{$approval->id}/decision", [
                'decision' => 'approved',
            ])
            ->assertForbidden();

        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_rejecting_requires_remarks(): void
    {
        $programLeader = $this->userWithRole('program-leader');
        $approval = $this->approvalFor($this->project($this->funder(), []), 'budget_realignment');

        $this->actingAs($programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", [
                'decision' => 'rejected',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('remarks');
    }

    public function test_approving_a_tranche_releases_it_and_writes_an_audit_row(): void
    {
        $programLeader = $this->userWithRole('program-leader');
        $project = $this->project($this->funder(), []);
        $tranche = ProjectTranche::create([
            'project_id' => $project->id,
            'sequence' => 1,
            'name' => 'Tranche 1',
            'amount' => 500000,
            'status' => 'due',
            'planned_release_date' => '2026-10-01',
        ]);
        $approval = $this->approvalFor($project, 'disbursement', [
            'project_tranche_id' => $tranche->id,
        ]);

        $this->actingAs($programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", [
                'decision' => 'approved',
            ])
            ->assertOk();

        $this->assertSame('released', $tranche->fresh()->status);
        $this->assertNotNull($tranche->fresh()->released_at);
        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertDatabaseHas('audit_trails', [
            'user_id' => $programLeader->id,
            'action' => 'approved',
            'model_type' => 'ApprovalRequest',
            'model_id' => $approval->id,
        ]);
    }

    public function test_approving_a_realignment_moves_allocation(): void
    {
        $programLeader = $this->userWithRole('program-leader');
        $project = $this->project($this->funder(), []);
        $source = LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => 'Professional Services',
            'allocated_amount' => 500000,
        ]);
        $target = LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => 'Traveling Expenses',
            'allocated_amount' => 100000,
        ]);
        $approval = $this->approvalFor($project, 'budget_realignment', [
            'amount' => 40000,
            'payload' => [
                'from_lib_category_id' => $source->id,
                'to_lib_category_id' => $target->id,
                'amount' => 40000,
            ],
        ]);

        $this->actingAs($programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", [
                'decision' => 'approved',
                'remarks' => 'Approved for field travel.',
            ])
            ->assertOk();

        $this->assertSame(460000.0, (float) $source->fresh()->allocated_amount);
        $this->assertSame(140000.0, (float) $target->fresh()->allocated_amount);
    }

    public function test_rejecting_a_liquidation_updates_the_liquidation_record(): void
    {
        $programLeader = $this->userWithRole('program-leader');
        $project = $this->project($this->funder(), []);
        $liquidation = Liquidation::create([
            'project_id' => $project->id,
            'title' => 'Liquidation report',
            'amount' => 250000,
            'due_date' => '2026-10-15',
            'status' => 'pending',
        ]);
        $approval = $this->approvalFor($project, 'liquidation', [
            'liquidation_id' => $liquidation->id,
        ]);

        $this->actingAs($programLeader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", [
                'decision' => 'rejected',
                'remarks' => 'Missing official receipts.',
            ])
            ->assertOk();

        $this->assertSame('rejected', $liquidation->fresh()->status);
        $this->assertEquals($programLeader->id, $liquidation->fresh()->reviewed_by);
        $this->assertSame('Missing official receipts.', $liquidation->fresh()->remarks);
        $this->assertSame('rejected', $approval->fresh()->status);
    }

    public function test_forecast_counts_overdue_tranches_in_the_current_month(): void
    {
        $programLeader = $this->userWithRole('program-leader');
        $project = $this->project($this->funder(), []);
        ProjectTranche::create([
            'project_id' => $project->id,
            'sequence' => 1,
            'name' => 'Overdue tranche',
            'amount' => 420000,
            'status' => 'due',
            'planned_release_date' => '2026-08-01',
        ]);
        ProjectTranche::create([
            'project_id' => $project->id,
            'sequence' => 2,
            'name' => 'December tranche',
            'amount' => 628000,
            'status' => 'upcoming',
            'planned_release_date' => '2026-12-15',
        ]);

        $response = $this->actingAs($programLeader)
            ->getJson('/api/program/overview')
            ->assertOk();

        $forecast = collect($response->json('forecast'))->keyBy('month');
        $this->assertSame(420000.0, (float) $forecast['2026-11']['amount']);
        $this->assertSame(628000.0, (float) $forecast['2026-12']['amount']);
    }

    public function test_exceptions_flag_self_benefiting_and_unused_line_items(): void
    {
        $programLeader = $this->userWithRole('program-leader');
        $project = $this->project($this->funder(), [
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'PS',
            'line_item_name' => 'Program Leader Indirect Honoraria',
            'allocated_amount' => 200000,
        ]);
        LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => 'Professional Services',
            'allocated_amount' => 500000,
        ]);

        $response = $this->actingAs($programLeader)
            ->getJson('/api/program/overview')
            ->assertOk();

        $this->assertCount(1, $response->json('exceptions.self_benefiting'));
        $this->assertCount(1, $response->json('exceptions.unused_line_items'));
    }

    protected function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::create([
            'name' => ucfirst($slug) . ' User',
            'email' => $slug . '.' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    protected function funder(): FundingAgency
    {
        return FundingAgency::create([
            'name' => 'MOST-BARMM ' . uniqid(),
            'acronym' => 'MOST',
        ]);
    }

    protected function project(FundingAgency $funder, array $overrides): Project
    {
        return Project::create(array_merge([
            'code' => 'TEST-' . uniqid(),
            'title' => 'Test project',
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ], $overrides));
    }

    protected function verifiedExpense(Project $project, float $amount): Expense
    {
        $category = LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => 'General expenses',
            'allocated_amount' => max($amount, $project->total_approved_budget),
        ]);

        return Expense::create([
            'project_id' => $project->id,
            'lib_category_id' => $category->id,
            'recorded_by' => $this->userWithRole('research-assistant')->id,
            'description' => 'Demo expense',
            'amount' => $amount,
            'expense_date' => '2026-06-01',
            'attachment_path' => 'receipts/demo.pdf',
            'status' => 'verified',
        ]);
    }

    protected function approvalFor(Project $project, string $type, array $overrides = []): ApprovalRequest
    {
        return ApprovalRequest::create(array_merge([
            'project_id' => $project->id,
            'type' => $type,
            'title' => 'Test approval',
            'amount' => 40000,
            'requested_by' => $this->userWithRole('project-leader')->id,
            'status' => 'pending',
        ], $overrides));
    }
}
