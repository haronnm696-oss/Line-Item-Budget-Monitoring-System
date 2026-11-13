<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditTrailTest extends TestCase
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

    public function test_audit_feed_resolves_the_project_and_readable_record_name(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();
        $category = LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => 'Traveling Expenses',
            'allocated_amount' => 100000,
        ]);

        AuditTrail::record($leader, 'created', $category, [], [
            'project_id' => $project->id,
            'line_item_name' => 'Traveling Expenses',
        ]);

        $this->actingAs($leader)
            ->getJson('/api/audit')
            ->assertOk()
            ->assertJsonPath('0.project', $project->title)
            ->assertJsonPath('0.record', 'LibCategory #' . $category->id . ' — Line item: Traveling Expenses');
    }

    public function test_audit_feed_filters_by_date_range_and_flagged_entries(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();

        $old = AuditTrail::record($leader, 'created', $project, [], ['title' => $project->title]);
        $old->forceFill(['created_at' => '2026-09-01 09:00:00'])->save();

        $rejected = AuditTrail::record(
            $leader,
            'rejected',
            $project,
            ['status' => 'pending'],
            ['status' => 'rejected'],
            'Missing official receipts.'
        );
        $rejected->forceFill(['created_at' => '2026-11-07 10:00:00'])->save();

        $roleChange = AuditTrail::record($leader, 'role_changed', $leader, ['role' => 'researcher'], ['role' => 'finance-admin'], 'Reassignment');
        $roleChange->forceFill(['created_at' => '2026-10-15 10:00:00'])->save();

        $range = $this->actingAs($leader)
            ->getJson('/api/audit?from=2026-11-01&to=2026-11-08')
            ->assertOk();

        $this->assertCount(1, $range->json());
        $this->assertSame('rejected', $range->json('0.action'));
        $this->assertSame('status: rejected; reason: Missing official receipts.', $range->json('0.change'));

        $flagged = $this->actingAs($leader)
            ->getJson('/api/audit?flagged=1')
            ->assertOk();

        $this->assertCount(2, $flagged->json());
        $this->assertSame(
            ['rejected', 'role_changed'],
            collect($flagged->json())->pluck('action')->sort()->values()->all()
        );
    }

    public function test_approving_a_realignment_logs_a_flagged_budget_change_with_its_reason(): void
    {
        $leader = $this->userWithRole('program-leader');
        $project = $this->project();
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
        $approval = $this->approvalFor($project, [
            'amount' => 40000,
            'payload' => [
                'from_lib_category_id' => $source->id,
                'to_lib_category_id' => $target->id,
                'amount' => 40000,
            ],
        ]);

        $this->actingAs($leader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", [
                'decision' => 'approved',
                'remarks' => 'Realign to field travel.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_trails', [
            'user_id' => $leader->id,
            'action' => 'budget_changed',
            'model_type' => 'ApprovalRequest',
            'model_id' => $approval->id,
            'reason' => 'Realign to field travel.',
        ]);

        $flagged = $this->actingAs($leader)
            ->getJson('/api/audit?flagged=1')
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertSame('budget_changed', $flagged->json('0.action'));
        $this->assertSame('Realign to field travel.', $flagged->json('0.change'));
        $this->assertSame($project->title, $flagged->json('0.project'));
    }

    private function project(): Project
    {
        $funder = FundingAgency::create([
            'name' => 'MOST-BARMM ' . uniqid(),
            'acronym' => 'MOST',
        ]);

        return Project::create([
            'code' => 'AUD-' . uniqid(),
            'title' => 'Audit test project',
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);
    }

    private function approvalFor(Project $project, array $overrides = []): ApprovalRequest
    {
        return ApprovalRequest::create(array_merge([
            'project_id' => $project->id,
            'type' => 'budget_realignment',
            'title' => 'Realignment request',
            'justification' => 'Field survey costs rose after the second site was added to the study area.',
            'amount' => 40000,
            'requested_by' => $this->userWithRole('project-leader')->id,
            'status' => 'pending',
        ], $overrides));
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
