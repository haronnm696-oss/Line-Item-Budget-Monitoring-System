<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProgramApprovalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approval_workspace_counts_each_tab_and_filters_by_type(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();

        $pending = $this->approval($project, 'disbursement', 'pending');
        $this->approval($project, 'budget_realignment', 'approved');
        $this->approval($project, 'liquidation', 'rejected');

        $response = $this->actingAs($leader)
            ->getJson('/api/program/approvals?status=pending')
            ->assertOk()
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('counts.approved', 1)
            ->assertJsonPath('counts.rejected', 1)
            ->assertJsonCount(1, 'approvals')
            ->assertJsonPath('approvals.0.id', $pending->id);

        $this->assertCount(2, $response->json('recent'));

        $realignment = $this->actingAs($leader)
            ->getJson('/api/program/approvals?status=approved&type=realignment')
            ->assertOk()
            ->assertJsonCount(1, 'approvals');

        $this->assertSame('budget_realignment', $realignment->json('approvals.0.type'));
    }

    public function test_overview_limits_the_inbox_to_three_and_reports_the_oldest_wait(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();

        foreach (range(1, 4) as $index) {
            $approval = $this->approval($project, 'disbursement', 'pending');
            $approval->forceFill(['created_at' => now()->subDays($index * 2)])->save();
        }

        $response = $this->actingAs($leader)
            ->getJson('/api/program/overview')
            ->assertOk()
            ->assertJsonCount(3, 'approvals');

        $this->assertSame(4, $response->json('kpis.pending_approvals_total'));
        $this->assertSame(8, $response->json('kpis.oldest_waiting_days'));
        $this->assertArrayHasKey('scheduled_releases', $response->json());
        $this->assertSame($response->json('forecast'), $response->json('scheduled_releases'));
    }

    public function test_completed_projects_are_closed_and_never_scored_for_under_spending(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project([
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'completed',
            'total_approved_budget' => 1000000,
        ]);

        $category = $this->category($project, 1000000);
        Expense::create([
            'project_id' => $project->id,
            'lib_category_id' => $category->id,
            'recorded_by' => $leader->id,
            'description' => 'Small verified expense',
            'amount' => 50000,
            'expense_date' => '2025-06-01',
            'status' => 'verified',
            'verified_by' => $leader->id,
            'verified_at' => now(),
        ]);

        $response = $this->actingAs($leader)
            ->getJson('/api/program/overview')
            ->assertOk();

        $this->assertSame('Closed', $response->json('projects.0.health'));
        $this->assertSame(1, $response->json('kpis.health.closed'));
        $this->assertSame(0, $response->json('kpis.health.at_risk'));
    }

    public function test_new_approval_requests_require_a_justification(): void
    {
        $project = $this->project();

        try {
            ApprovalRequest::create([
                'project_id' => $project->id,
                'type' => 'disbursement',
                'title' => 'No justification',
                'amount' => 10000,
                'requested_by' => $this->userWithRole('project-leader')->id,
                'status' => 'pending',
            ]);
            $this->fail('An approval request without a justification was saved.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['A justification is required for new approval requests.'],
                $exception->errors()['justification'] ?? null
            );
        }

        $this->assertSame(0, ApprovalRequest::count());
    }

    public function test_new_approval_requests_with_a_justification_are_saved(): void
    {
        $project = $this->project();

        $approval = ApprovalRequest::create([
            'project_id' => $project->id,
            'type' => 'disbursement',
            'title' => 'Second tranche release',
            'justification' => 'First tranche is fully liquidated and fieldwork for phase two starts next month.',
            'amount' => 10000,
            'requested_by' => $this->userWithRole('project-leader')->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approval->id,
            'justification' => 'First tranche is fully liquidated and fieldwork for phase two starts next month.',
        ]);
    }

    private function project(array $overrides = []): Project
    {
        $funder = FundingAgency::firstOrCreate(
            ['acronym' => 'MOST-BARMM'],
            ['name' => 'MOST-BARMM']
        );

        return Project::create(array_merge([
            'code' => 'PRG-APR-' . uniqid(),
            'title' => 'Approval test project',
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ], $overrides));
    }

    private function category(Project $project, float $amount): LibCategory
    {
        return LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => 'Field travel',
            'allocated_amount' => $amount,
        ]);
    }

    private function approval(Project $project, string $type, string $status): ApprovalRequest
    {
        return ApprovalRequest::create([
            'project_id' => $project->id,
            'type' => $type,
            'title' => $type . ' request',
            'justification' => 'Requested to keep project activities on schedule for this quarter.',
            'amount' => 10000,
            'requested_by' => $this->userWithRole('project-leader')->id,
            'status' => $status,
            'reviewed_at' => $status === 'pending' ? null : now(),
        ]);
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::create([
            'name' => ucfirst($slug) . ' User ' . uniqid(),
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
