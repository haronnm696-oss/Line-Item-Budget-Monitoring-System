<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Project scoping: everyone except the Program Leader sees only the projects
 * they are assigned to, and gets 403 for any other project. Budget-request
 * decisions are Program Leader-only. A write to an endpoint that does not
 * exist stores nothing.
 */
class ProjectScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;
    private Project $mine;
    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->leader, $this->mine, $this->other] = AuditLogger::muted(fn () => [
            $this->person('program-leader'),
            $this->project('MINE-001', 'Assigned project'),
            $this->project('OTHER-002', 'Someone else\'s project'),
        ]);
    }

    public function test_project_list_and_project_endpoints_are_scoped_to_assignments(): void
    {
        foreach (['project-leader', 'research-assistant', 'finance-admin', 'researcher'] as $slug) {
            $user = AuditLogger::muted(function () use ($slug) {
                $user = $this->person($slug);
                $this->assign($user, $this->mine, $slug);

                return $user;
            });
            $this->actingAs($user);

            $this->assertSame(['MINE-001'], array_column($this->getJson('/api/projects')->assertOk()->json(), 'code'), "{$slug}: list");

            foreach (['', '/tranches', '/burn-rate', '/compliance', '/reports/utilization'] as $suffix) {
                $this->get("/api/projects/{$this->mine->id}{$suffix}")->assertOk();
                $this->getJson("/api/projects/{$this->other->id}{$suffix}")
                    ->assertForbidden()
                    ->assertJsonPath('message', 'You are not assigned to this project.');
            }

            $this->getJson("/api/dashboard/program-leader?project_id={$this->other->id}")->assertForbidden();
            $this->getJson("/api/approvals?project_id={$this->other->id}")->assertForbidden();
        }
    }

    public function test_an_unassigned_user_sees_no_projects(): void
    {
        $nobody = AuditLogger::muted(fn () => $this->person('researcher'));

        $this->actingAs($nobody)->getJson('/api/projects')->assertOk()->assertExactJson([]);
        $this->actingAs($nobody)->getJson("/api/projects/{$this->mine->id}")->assertForbidden();
    }

    public function test_the_program_leader_sees_every_project(): void
    {
        $codes = array_column($this->actingAs($this->leader)->getJson('/api/projects')->assertOk()->json(), 'code');
        $this->assertEqualsCanonicalizing(['MINE-001', 'OTHER-002'], $codes);
        $this->actingAs($this->leader)->getJson("/api/projects/{$this->other->id}")->assertOk();
    }

    public function test_lists_of_approvals_and_audit_entries_only_cover_assigned_projects(): void
    {
        $user = AuditLogger::muted(function () {
            $user = $this->person('project-leader');
            $this->assign($user, $this->mine, 'project-leader');
            $this->approval($this->mine, 'Mine');
            $this->approval($this->other, 'Other');

            return $user;
        });
        AuditTrail::record($this->leader, 'updated', $this->mine, ['title' => 'a'], ['title' => 'b']);
        AuditTrail::record($this->leader, 'updated', $this->other, ['title' => 'c'], ['title' => 'd']);

        $titles = array_column($this->actingAs($user)->getJson('/api/approvals')->assertOk()->json('approvals'), 'title');
        $this->assertSame(['Mine'], $titles);

        $records = array_column($this->actingAs($user)->getJson('/api/audit')->assertOk()->json(), 'record');
        $this->assertCount(1, $records);
        $this->assertStringStartsWith("Project #{$this->mine->id}", $records[0]);
        $this->actingAs($user)->getJson("/api/audit?project_id={$this->other->id}")->assertForbidden();
    }

    public function test_finance_cannot_verify_an_expense_of_a_project_they_are_not_assigned_to(): void
    {
        [$finance, $expense] = AuditLogger::muted(function () {
            $finance = $this->person('finance-admin');
            $this->assign($finance, $this->mine, 'finance-admin');
            $category = LibCategory::create(['project_id' => $this->other->id, 'category_type' => 'MOOE', 'line_item_name' => 'Supplies', 'allocated_amount' => 10000]);
            $expense = Expense::create([
                'project_id' => $this->other->id, 'lib_category_id' => $category->id, 'recorded_by' => $this->leader->id,
                'description' => 'Other project expense', 'amount' => 500, 'expense_date' => '2026-11-01', 'status' => 'pending',
            ]);

            return [$finance, $expense];
        });

        $this->actingAs($finance)->patchJson("/api/expenses/{$expense->id}/verify")->assertForbidden();
        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_only_the_program_leader_decides_budget_requests(): void
    {
        $approval = AuditLogger::muted(fn () => $this->approval($this->mine, 'Realign travel'));

        foreach (['project-leader', 'finance-admin', 'research-assistant', 'researcher'] as $slug) {
            $user = AuditLogger::muted(function () use ($slug) {
                $user = $this->person($slug);
                $this->assign($user, $this->mine, $slug);

                return $user;
            });

            $this->actingAs($user)
                ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved'])
                ->assertForbidden();
            // The legacy decision routes no longer exist.
            $this->actingAs($user)->patchJson("/api/approvals/{$approval->id}/approve")->assertNotFound();
            $this->actingAs($user)->patchJson("/api/approvals/{$approval->id}/reject", ['review_notes' => 'x'])->assertNotFound();
        }

        $this->assertSame('pending', $approval->fresh()->status);

        $this->actingAs($this->leader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'rejected', 'remarks' => 'Not justified.'])
            ->assertOk();
        $this->assertSame('rejected', $approval->fresh()->status);
    }

    public function test_a_refused_or_missing_write_stores_nothing(): void
    {
        $assistant = AuditLogger::muted(function () {
            $assistant = $this->person('research-assistant');
            $this->assign($assistant, $this->mine, 'research-assistant');

            return $assistant;
        });

        $before = [Expense::count(), AuditTrail::count()];

        // The Expenses and Payroll pages used to treat 404s as success. The
        // expense endpoint now exists: an incomplete payload (no category or
        // date) is refused with 422 and stores nothing.
        $this->actingAs($assistant)
            ->postJson("/api/projects/{$this->mine->id}/expenses", ['description' => 'Field forms', 'amount' => 1500])
            ->assertStatus(422);
        $this->actingAs($assistant)
            ->postJson("/api/projects/{$this->mine->id}/payroll/compute", ['month' => '2026-11'])
            ->assertNotFound();

        $this->assertSame($before, [Expense::count(), AuditTrail::count()]);
    }

    private function person(string $slug): User
    {
        return User::create([
            'name' => ucfirst($slug) . ' ' . uniqid(),
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function project(string $code, string $title): Project
    {
        $funder = FundingAgency::firstOrCreate(['acronym' => 'MOST-BARMM'], ['name' => 'MOST-BARMM']);

        return Project::create([
            'code' => $code, 'title' => $title, 'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'ongoing',
        ]);
    }

    private function assign(User $user, Project $project, string $slug): void
    {
        $roleSlug = $slug === 'program-leader' ? 'project-leader' : $slug;
        ProjectUser::create([
            'project_id' => $project->id, 'user_id' => $user->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'), 'assigned_at' => now(),
        ]);
    }

    private function approval(Project $project, string $title): ApprovalRequest
    {
        return ApprovalRequest::create([
            'project_id' => $project->id, 'type' => 'disbursement', 'title' => $title,
            'justification' => 'Needed for fieldwork.', 'amount' => 10000,
            'requested_by' => $this->person('project-leader')->id, 'status' => 'pending',
        ]);
    }
}
