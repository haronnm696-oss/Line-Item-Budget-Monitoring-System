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

        $this->actingAs($leader)
            ->getJson('/api/program/approvals?status=pending')
            ->assertOk()
            ->assertJsonPath('counts.pending', 1)
            ->assertJsonPath('counts.approved', 1)
            ->assertJsonPath('counts.returned', 1)
            ->assertJsonPath('counts.withdrawn', 0)
            ->assertJsonCount(1, 'approvals')
            ->assertJsonPath('approvals.0.id', $pending->id);

        $realignment = $this->actingAs($leader)
            ->getJson('/api/program/approvals?status=approved&type=realignment')
            ->assertOk()
            ->assertJsonCount(1, 'approvals');

        $this->assertSame('budget_realignment', $realignment->json('approvals.0.type'));
    }

    public function test_the_type_filter_matches_the_labels_and_drives_the_tab_counts(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();

        $disbursement = $this->approval($project, 'disbursement', 'pending');
        $this->approval($project, 'disbursement', 'approved');
        $payroll = $this->approval($project, 'payroll', 'pending');
        $realignment = $this->approval($project, 'budget_realignment', 'pending');
        $this->approval($project, 'budget_realignment', 'rejected');
        $liquidation = $this->approval($project, 'liquidation', 'pending');

        $expected = [
            // type => [pending ids, [pending, approved, returned] counts] (stored "rejected" = returned)
            'fund-release' => [[$disbursement->id], [1, 1, 0]],
            'payroll' => [[$payroll->id], [1, 0, 0]],
            'realignment' => [[$realignment->id], [1, 0, 1]],
            'liquidation' => [[$liquidation->id], [1, 0, 0]],
            'all' => [[$disbursement->id, $payroll->id, $realignment->id, $liquidation->id], [4, 1, 1]],
        ];

        foreach ($expected as $type => [$ids, [$pending, $approved, $returned]]) {
            $response = $this->actingAs($leader)
                ->getJson("/api/program/approvals?status=pending&type={$type}")
                ->assertOk()
                ->assertJsonPath('counts.pending', $pending)
                ->assertJsonPath('counts.approved', $approved)
                ->assertJsonPath('counts.returned', $returned);

            $this->assertEqualsCanonicalizing($ids, collect($response->json('approvals'))->pluck('id')->all(), "type={$type}");
        }

        // "Budget change" is gone (it duplicated Realignment).
        $this->actingAs($leader)
            ->getJson('/api/program/approvals?type=budget-change')
            ->assertStatus(422);
    }

    public function test_the_list_accepts_every_type_on_every_tab(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();

        // Every stored type is a filter option.
        $filterStoredTypes = array_column(ApprovalRequest::FILTER_TYPES, 'type');
        foreach (array_keys(ApprovalRequest::TYPES) as $storedType) {
            $this->assertContains($storedType, $filterStoredTypes, "{$storedType} has no Type filter option");
        }

        // One request per stored type in each tab.
        foreach (array_keys(ApprovalRequest::TYPES) as $storedType) {
            $this->approval($project, $storedType, 'pending');
            $this->approval($project, $storedType, 'approved');
            $this->approval($project, $storedType, 'rejected');
            $this->approval($project, $storedType, 'rejected')->forceFill(['withdrawn_at' => now()])->save();
        }

        $tabs = array_merge(array_keys(ApprovalRequest::STATUS_LABELS), [ApprovalRequest::STORED_RETURNED]);

        foreach (ApprovalRequest::filterTypeValues() as $type) {
            foreach ($tabs as $tab) {
                $query = http_build_query([
                    'status' => $tab,
                    'type' => $type,
                    'project' => $project->id,
                    'funder' => $project->funding_agency_id,
                ]);

                $response = $this->actingAs($leader)
                    ->getJson('/api/program/approvals?' . $query)
                    ->assertOk()
                    ->assertJsonPath('types', ApprovalRequest::filterTypeOptions());

                $rows = collect($response->json('approvals'));
                $label = "type={$type} tab={$tab}";
                $this->assertCount($type === 'all' ? count(ApprovalRequest::TYPES) : 1, $rows, $label);

                if ($type !== 'all') {
                    $this->assertSame([ApprovalRequest::storedTypeForFilter($type)], $rows->pluck('type')->unique()->values()->all(), $label);
                }
            }
        }
    }

    public function test_the_expense_verification_filter_from_the_bug_report_is_accepted(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();
        $expense = $this->approval($project, 'expense', 'approved');
        $this->approval($project, 'disbursement', 'approved');

        $response = $this->actingAs($leader)
            ->getJson("/api/program/approvals?status=approved&type=expense&project={$project->id}&funder={$project->funding_agency_id}")
            ->assertOk()
            ->assertJsonCount(1, 'approvals')
            ->assertJsonPath('approvals.0.id', $expense->id);

        $this->assertContains(
            ['value' => 'expense', 'label' => 'Expense verification'],
            $response->json('types')
        );
    }

    public function test_pending_count_matches_the_overview_kpi_for_the_same_funder(): void
    {
        $leader = $this->userWithRole('program-leader');
        $other = FundingAgency::create(['acronym' => 'DOST', 'name' => 'DOST']);

        $most = $this->project();
        $dost = $this->project(['funding_agency_id' => $other->id]);
        $deleted = $this->project();

        $this->approval($most, 'disbursement', 'pending');
        $this->approval($most, 'liquidation', 'pending');
        $this->approval($most, 'budget_realignment', 'approved');
        $this->approval($dost, 'disbursement', 'pending');
        $this->approval($deleted, 'disbursement', 'pending');
        $deleted->delete();

        foreach (['', $most->funding_agency_id, $other->id, 'MOST-BARMM'] as $funder) {
            $query = $funder === '' ? '' : '?funder=' . $funder;

            $kpi = $this->actingAs($leader)
                ->getJson('/api/program/overview' . $query)
                ->assertOk()
                ->json('kpis.pending_approvals');

            $separator = $query === '' ? '?' : '&';
            $count = $this->actingAs($leader)
                ->getJson('/api/program/approvals' . $query . $separator . 'status=pending')
                ->assertOk()
                ->json('counts.pending');

            $this->assertSame($kpi, $count, 'Pending count differs for funder [' . $funder . ']');
        }

        $this->assertSame(3, $this->actingAs($leader)->getJson('/api/program/approvals')->json('counts.pending'));
    }

    public function test_decided_requests_are_newest_decision_first_with_their_remark(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();

        $older = $this->approval($project, 'disbursement', 'approved');
        $older->forceFill([
            'reviewed_at' => now()->subDays(5),
            'reviewed_by' => $leader->id,
            'review_notes' => 'Receipts for tranche one are complete.',
        ])->save();

        $newer = $this->approval($project, 'liquidation', 'approved');
        $newer->forceFill([
            'created_at' => now()->subDays(10),
            'reviewed_at' => now()->subDay(),
            'reviewed_by' => $leader->id,
            'review_notes' => null,
        ])->save();

        $response = $this->actingAs($leader)
            ->getJson('/api/program/approvals?status=approved')
            ->assertOk()
            ->assertJsonCount(2, 'approvals')
            ->assertJsonPath('approvals.0.id', $newer->id)
            ->assertJsonPath('approvals.0.review_notes', null)
            ->assertJsonPath('approvals.0.reviewed_by', $leader->name)
            ->assertJsonPath('approvals.1.id', $older->id)
            ->assertJsonPath('approvals.1.review_notes', 'Receipts for tranche one are complete.');

        $first = $response->json('approvals.0');
        foreach (['requested_by', 'created_at', 'reviewed_by', 'reviewed_at', 'review_notes', 'justification'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
        $this->assertSame(
            'Requested to keep project activities on schedule for this quarter.',
            $first['justification']
        );
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
