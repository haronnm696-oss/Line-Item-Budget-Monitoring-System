<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakerCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_program_leader_cannot_approve_a_request_they_created(): void
    {
        $leader = $this->userWithRole('program-leader');

        $project = Project::create([
            'code' => 'MOST-RD-2026-099',
            'title' => 'Maker checker project',
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);

        $approval = ApprovalRequest::create([
            'project_id' => $project->id,
            'type' => 'budget_realignment',
            'title' => 'Self-created realignment',
            'justification' => 'Supplies savings are needed to cover additional travel for data collection.',
            'amount' => 10000,
            'requested_by' => $leader->id,
            'status' => 'pending',
        ]);

        $this->actingAs($leader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", [
                'decision' => 'approved',
                'remarks' => 'Trying to self-approve.',
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot approve or reject a request you created.');

        $this->assertSame('pending', $approval->fresh()->status);

        $this->actingAs($leader)
            ->patchJson("/api/approvals/{$approval->id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot approve or reject a request you created.');

        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_a_finance_admin_cannot_verify_an_expense_they_created(): void
    {
        $finance = $this->userWithRole('finance-admin');
        $project = Project::create([
            'code' => 'MOST-RD-2026-098',
            'title' => 'Finance maker checker project',
            'total_approved_budget' => 500000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);

        $category = \App\Models\LibCategory::create([
            'project_id' => $project->id,
            'category_type' => 'MOOE',
            'line_item_name' => 'Field travel',
            'allocated_amount' => 100000,
        ]);

        $expense = \App\Models\Expense::create([
            'project_id' => $project->id,
            'lib_category_id' => $category->id,
            'recorded_by' => $finance->id,
            'description' => 'Self-created expense',
            'amount' => 5000,
            'expense_date' => '2026-10-01',
            'status' => 'pending',
        ]);

        $this->actingAs($finance)
            ->patchJson("/api/expenses/{$expense->id}/verify")
            ->assertForbidden()
            ->assertJsonPath('message', 'You cannot verify or reject an expense you recorded.');

        $this->assertSame('pending', $expense->fresh()->status);
    }

    private function userWithRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::create([
            'name' => ucfirst($slug) . ' User',
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }
}
