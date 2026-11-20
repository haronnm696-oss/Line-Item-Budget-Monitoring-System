<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Concerns\GeneratesEmployeeIds;
use Tests\TestCase;

class ProgramUsersDirectoryTest extends TestCase
{
    use GeneratesEmployeeIds;
    use RefreshDatabase;

    private User $leader;
    private Project $urban;
    private Project $goat;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        // Fixtures without automatic audit entries, so audit counts below are
        // only what each action writes.
        [$this->leader, $this->urban, $this->goat] = AuditLogger::muted(fn () => [
            $this->person('program-leader', 'Dr. Rahana Bayabao', 'MSU-05561', 'Program Leader, OVCRE'),
            $this->project('MOST-RD-2026-001', 'Enhancing Urban Mobility in Marawi City'),
            $this->project('MOST-RD-2026-002', 'Halal Goat Value Chain Development'),
        ]);
    }

    // --------------------------------------------------------- list rows

    public function test_one_row_per_person_with_assignments_and_all_projects_for_program_leaders(): void
    {
        $assistant = AuditLogger::muted(function () {
            $assistant = $this->person('research-assistant', 'Norjannah Haron', 'MSU-09214', 'Research Assistant');
            $this->assign($assistant, $this->urban, 'research-assistant');
            $this->assign($assistant, $this->goat, 'researcher');
            // A Program Leader with a leftover assignment row: kept, not shown as scope.
            $this->assign($this->leader, $this->urban, 'project-leader');
            $this->person('researcher', 'Unassigned Person', 'MSU-11111', null);

            return $assistant;
        });

        $rows = collect($this->list()['users'])->keyBy('name');

        $this->assertCount(3, $rows, 'one row per person, not per assignment');

        $this->assertSame('Research Assistant', $rows['Norjannah Haron']['role_label']);
        $this->assertSame([
            ['project_id' => $this->urban->id, 'project' => 'Enhancing Urban Mobility in Marawi City', 'project_code' => 'MOST-RD-2026-001', 'role_slug' => 'research-assistant', 'role_label' => 'Research Assistant', 'role_mismatch' => false],
            // Older data: Researcher on a project while the account is a Research Assistant.
            ['project_id' => $this->goat->id, 'project' => 'Halal Goat Value Chain Development', 'project_code' => 'MOST-RD-2026-002', 'role_slug' => 'researcher', 'role_label' => 'Researcher', 'role_mismatch' => true],
        ], $rows['Norjannah Haron']['assignments']);
        $this->assertFalse($rows['Norjannah Haron']['program_wide']);
        $this->assertSame($assistant->id, $rows['Norjannah Haron']['id']);

        $this->assertTrue($rows['Dr. Rahana Bayabao']['program_wide']);
        $this->assertTrue($rows['Dr. Rahana Bayabao']['is_self']);
        $this->assertCount(1, $rows['Dr. Rahana Bayabao']['assignments'], 'leftover rows are returned, not deleted');
        $this->assertDatabaseHas('project_user', ['user_id' => $this->leader->id, 'project_id' => $this->urban->id]);

        $this->assertSame([], $rows['Unassigned Person']['assignments']);
        $this->assertNull($rows['Unassigned Person']['position']);
    }

    public function test_filters_search_role_project_and_status_on_the_server(): void
    {
        AuditLogger::muted(function () {
            $assistant = $this->person('research-assistant', 'Norjannah Haron', 'MSU-09214');
            $this->assign($assistant, $this->urban, 'research-assistant');
            $finance = $this->person('finance-admin', 'Faisal Sarip', 'MSU-03327');
            $this->assign($finance, $this->goat, 'finance-admin');
            $gone = $this->person('researcher', 'Former Researcher', 'MSU-77777');
            $gone->update(['is_active' => false]);
            // A pending registration never appears in the list.
            User::create(['name' => 'Pending Applicant', 'email' => 'pending@msumain.edu.ph', 'password' => Hash::make('x-pass-1'), 'is_active' => false]);
        });

        $this->assertSame(['Dr. Rahana Bayabao', 'Faisal Sarip', 'Norjannah Haron'], $this->names([]), 'Active by default');
        $this->assertSame(['Former Researcher'], $this->names(['status' => 'inactive']));
        $this->assertSame(['Dr. Rahana Bayabao', 'Faisal Sarip', 'Former Researcher', 'Norjannah Haron'], $this->names(['status' => 'all']));
        $this->assertSame(['Norjannah Haron'], $this->names(['q' => 'norjannah']));
        $this->assertSame(['Faisal Sarip'], $this->names(['q' => '03327']), 'search matches employee ID');
        $this->assertSame(['Faisal Sarip'], $this->names(['role' => 'finance-admin']));
        // Program Leaders oversee every project, so they appear under any project.
        $this->assertSame(['Dr. Rahana Bayabao', 'Faisal Sarip'], $this->names(['project' => $this->goat->id]));
        $this->assertSame([], $this->names(['q' => '%']), 'wildcards are searched literally');

        $this->actingAs($this->leader)->getJson('/api/program/users?status=deleted')->assertStatus(422);
    }

    public function test_pages_of_25_and_the_user_parameter_reaches_that_person(): void
    {
        $target = AuditLogger::muted(function () {
            foreach (range(1, 59) as $i) {
                $this->person('researcher', sprintf('Researcher %02d', $i), sprintf('MSU-2%04d', $i));
            }
            $target = User::where('name', 'Researcher 40')->first();
            $target->update(['is_active' => false]);

            return $target;
        });

        $first = $this->list();
        $this->assertCount(25, $first['users']);
        $this->assertSame(59, $first['meta']['total']);
        $this->assertTrue($first['meta']['has_more']);

        $third = $this->list(['page' => 3]);
        $this->assertCount(9, $third['users']);
        $this->assertFalse($third['meta']['has_more']);

        // ?user= alone shows all statuses and extends page 1 to include the person.
        $focused = $this->list(['user' => $target->id]);
        $this->assertSame('all', $focused['meta']['status']);
        $this->assertTrue($focused['meta']['focus_found']);
        $this->assertContains($target->id, array_column($focused['users'], 'id'));
        $this->assertCount(50, $focused['users']);
        $this->assertSame(2, $focused['meta']['page'], 'Load more continues at page 3');

        $this->assertFalse($this->list(['user' => $target->id, 'status' => 'active'])['meta']['focus_found']);
    }

    public function test_the_list_costs_the_same_number_of_queries_for_3_or_25_people(): void
    {
        $count = function (int $people) {
            AuditLogger::muted(function () use ($people) {
                foreach (range(1, $people) as $i) {
                    $person = $this->person('researcher', 'Bulk ' . uniqid(), $this->nextEmployeeId());
                    $this->assign($person, $this->urban, 'researcher');
                }
            });
            $this->list(); // warm-up (role middleware caches the actor's role)
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->list();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(2), $count(30));
    }

    // ---------------------------------------------------- manage actions

    public function test_edit_details_validates_unique_email_and_employee_id_and_logs_one_entry(): void
    {
        [$person, $other] = AuditLogger::muted(fn () => [
            $this->person('researcher', 'Sittie Dimaporo', 'MSU-11048', 'Research Aide I'),
            $this->person('researcher', 'Other Person', 'MSU-22222'),
        ]);

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}", ['name' => 'Sittie Dimaporo', 'email' => $other->email, 'employee_id' => 'MSU-22222'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'employee_id']);
        $this->assertSame(0, AuditTrail::count());

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}", [
                'name' => 'Sittie A. Dimaporo',
                'position' => 'Researcher',
                'email' => ' SITTIE.D@msumain.edu.ph ',
                'employee_id' => 'msu-11048',
            ])
            ->assertOk();

        $person->refresh();
        $this->assertSame(['Sittie A. Dimaporo', 'Researcher', 'sittie.d@msumain.edu.ph', 'MSU-11048'], [$person->name, $person->position, $person->email, $person->employee_id]);

        $entries = AuditTrail::all();
        $this->assertCount(1, $entries, 'exactly one audit entry');
        $this->assertSame('updated', $entries[0]->action);
        $this->assertSame($this->leader->id, (int) $entries[0]->user_id);
        $this->assertSame(['name', 'position', 'email'], array_keys($entries[0]->new_values), 'only changed fields');
    }

    public function test_role_status_and_assignment_actions_each_write_exactly_one_entry(): void
    {
        $person = AuditLogger::muted(fn () => $this->person('researcher', 'Faisal Sarip', 'MSU-03327'));

        $steps = [
            fn () => $this->patchJson("/api/program/users/{$person->id}/role", ['role' => 'project-leader', 'reason' => 'Leads a project']),
            fn () => $this->postJson('/api/program/assignments', ['user_id' => $person->id, 'project_id' => $this->urban->id, 'role' => 'project-leader']),
            fn () => $this->patchJson("/api/program/users/{$person->id}/status", ['is_active' => false, 'reason' => 'Left the university']),
            fn () => $this->patchJson("/api/program/users/{$person->id}/status", ['is_active' => true]),
        ];

        foreach ($steps as $index => $step) {
            $before = AuditTrail::count();
            $this->actingAs($this->leader);
            $step()->assertSuccessful();
            $this->assertSame($before + 1, AuditTrail::count(), "step {$index} wrote exactly one entry");
        }

        $this->assertSame(['role_changed', 'assignment_created', 'deactivated', 'activated'], AuditTrail::orderBy('id')->pluck('action')->all());

        // Repeating a status that is already set changes and logs nothing.
        $this->actingAs($this->leader)->patchJson("/api/program/users/{$person->id}/status", ['is_active' => true])->assertOk();
        $this->assertSame(4, AuditTrail::count());
    }

    public function test_deactivation_needs_a_reason_and_guards_self_and_the_last_program_leader(): void
    {
        $person = AuditLogger::muted(fn () => $this->person('researcher', 'Some Researcher', 'MSU-44444'));

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}/status", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$this->leader->id}/status", ['is_active' => false, 'reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot deactivate your own account.');

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$this->leader->id}/role", ['role' => 'researcher'])
            ->assertStatus(422);

        // Another Program Leader cannot remove the last active one either.
        $second = AuditLogger::muted(fn () => $this->person('program-leader', 'Second Leader', 'MSU-55555'));
        $this->actingAs($second)
            ->patchJson("/api/program/users/{$this->leader->id}/status", ['is_active' => false, 'reason' => 'Handover'])
            ->assertOk();
        $this->actingAs($this->leader->fresh())->getJson('/api/me')->assertStatus(401);

        $this->actingAs($second->fresh())
            ->patchJson("/api/program/users/{$second->id}/role", ['role' => 'researcher'])
            ->assertStatus(422);

        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame(3, User::count(), 'nobody is deleted');
    }

    public function test_a_deactivated_user_cannot_log_in_or_keep_using_a_session_and_reactivation_restores_access(): void
    {
        $person = AuditLogger::muted(fn () => $this->person('researcher', 'Session Holder', 'MSU-66666'));

        $this->postJson('/api/login', ['email' => $person->email, 'password' => 'password123'])->assertOk();
        DB::table('sessions')->insert(['id' => 'sess-' . uniqid(), 'user_id' => $person->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}/status", ['is_active' => false, 'reason' => 'Contract ended'])
            ->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $person->id)->count(), 'sessions revoked');
        $this->actingAs($person->fresh())->getJson('/api/me')->assertStatus(401);
        $this->actingAs($person->fresh())->getJson('/api/projects')->assertStatus(401);

        auth()->forgetGuards();
        $this->postJson('/api/login', ['email' => $person->email, 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonPath('message', 'This account is deactivated. Contact your administrator.');

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}/status", ['is_active' => true])
            ->assertOk();

        auth()->forgetGuards();
        $this->postJson('/api/login', ['email' => $person->email, 'password' => 'password123'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $person->id, 'is_active' => true]);
    }

    public function test_only_program_leaders_can_use_the_user_management_endpoints(): void
    {
        $person = AuditLogger::muted(fn () => $this->person('researcher', 'Target Person', 'MSU-88888'));

        foreach (['project-leader', 'researcher', 'research-assistant', 'finance-admin'] as $slug) {
            $actor = AuditLogger::muted(fn () => $this->person($slug, 'Actor ' . $slug, $this->nextEmployeeId()));
            $this->actingAs($actor);

            $this->getJson('/api/program/users')->assertForbidden();
            $this->patchJson("/api/program/users/{$person->id}", ['name' => 'X', 'email' => 'x@msumain.edu.ph'])->assertForbidden();
            $this->patchJson("/api/program/users/{$person->id}/role", ['role' => 'project-leader'])->assertForbidden();
            $this->patchJson("/api/program/users/{$person->id}/status", ['is_active' => false, 'reason' => 'x'])->assertForbidden();
            $this->postJson('/api/program/assignments', ['user_id' => $person->id, 'project_id' => $this->urban->id, 'role' => 'researcher'])->assertForbidden();
        }

        $this->assertSame('Target Person', $person->fresh()->name);
        $this->assertTrue($person->fresh()->is_active);
        $this->assertSame(0, AuditTrail::count());
    }

    // ------------------------------------------ edit details: format rules

    public function test_unchanged_legacy_email_and_employee_id_are_accepted(): void
    {
        $legacy = AuditLogger::muted(function () {
            $person = $this->person('project-leader', 'Project Leader Demo', 'MSU-DEMO02');
            $person->update(['email' => 'project.leader@gmail.com']);

            return $person;
        });

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$legacy->id}", [
                'name' => 'Project Leader Demo',
                'position' => 'Project Leader, CNSM',
                'email' => 'project.leader@gmail.com',
                'employee_id' => 'msu-demo02',
            ])
            ->assertOk();

        $legacy->refresh();
        $this->assertSame(['project.leader@gmail.com', 'MSU-DEMO02', 'Project Leader, CNSM'], [$legacy->email, $legacy->employee_id, $legacy->position]);
        $this->assertSame(['position'], array_keys(AuditTrail::sole()->new_values));
    }

    public function test_a_changed_email_or_employee_id_must_use_the_msu_formats(): void
    {
        $person = AuditLogger::muted(function () {
            $person = $this->person('researcher', 'Legacy Person', 'MSU-DEMO04');
            $person->update(['email' => 'legacy@gmail.com']);

            return $person;
        });

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}", ['name' => 'Legacy Person', 'email' => 'new@gmail.com', 'employee_id' => 'MSU-DEMO04'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'A new email must end with @msumain.edu.ph.'])
            ->assertJsonMissingValidationErrors(['employee_id']);

        foreach (['MSU-123', 'EMP-12345', ''] as $badId) {
            $this->actingAs($this->leader)
                ->patchJson("/api/program/users/{$person->id}", ['name' => 'Legacy Person', 'email' => 'legacy@gmail.com', 'employee_id' => $badId])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['employee_id' => 'A new employee ID must use the format MSU-00000.'])
                ->assertJsonMissingValidationErrors(['email']);
        }

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}", ['name' => 'Legacy Person', 'email' => 'legacy.person@msumain.edu.ph', 'employee_id' => 'msu-12345'])
            ->assertOk();

        $person->refresh();
        $this->assertSame(['legacy.person@msumain.edu.ph', 'MSU-12345'], [$person->email, $person->employee_id]);
        $this->assertSame(1, AuditTrail::count());
    }

    // ------------------------------------------- edit project assignments

    public function test_fix_sets_a_mismatched_project_role_to_the_account_role_with_one_entry(): void
    {
        // Older data: a Research Assistant account holding a Researcher row.
        $person = AuditLogger::muted(function () {
            $person = $this->person('research-assistant', 'Sittie Dimaporo', 'MSU-11048');
            $this->assign($person, $this->urban, 'researcher');

            return $person;
        });

        $row = collect($this->list()['users'])->firstWhere('id', $person->id);
        $this->assertTrue($row['assignments'][0]['role_mismatch']);

        // A role other than the account role is refused.
        $this->actingAs($this->leader)
            ->patchJson("/api/program/assignments/{$person->id}/{$this->urban->id}", ['role' => 'finance-admin'])
            ->assertStatus(422);
        $this->assertSame(0, AuditTrail::count());

        // Fix: no role sent; the row takes the account role.
        $this->actingAs($this->leader)
            ->patchJson("/api/program/assignments/{$person->id}/{$this->urban->id}")
            ->assertOk();
        $this->assertSame('research-assistant', ProjectUser::where('user_id', $person->id)->first()->role->slug);

        $entry = AuditTrail::sole();
        $this->assertSame('assignment_updated', $entry->action);
        $this->assertSame(['researcher', 'research-assistant'], [$entry->old_values['role'], $entry->new_values['role']]);
        $row = collect($this->list()['users'])->firstWhere('id', $person->id);
        $this->assertFalse($row['assignments'][0]['role_mismatch']);

        $this->actingAs($this->leader)
            ->patchJson("/api/program/assignments/{$person->id}/{$this->urban->id}")
            ->assertOk();
        $this->assertSame(1, AuditTrail::count(), 'already matching: nothing logged');
    }

    public function test_removal_needs_a_reason_writes_one_entry_and_keeps_history(): void
    {
        [$person, $expenseId] = AuditLogger::muted(function () {
            $person = $this->person('finance-admin', 'Faisal Sarip', 'MSU-03327');
            $this->assign($person, $this->urban, 'finance-admin');
            $category = \App\Models\LibCategory::create(['project_id' => $this->urban->id, 'category_type' => 'MOOE', 'line_item_name' => 'Supplies', 'allocated_amount' => 50000]);
            $expense = \App\Models\Expense::create([
                'project_id' => $this->urban->id, 'lib_category_id' => $category->id, 'recorded_by' => $person->id,
                'reference_no' => 'URB-1', 'description' => 'Field forms', 'amount' => 1500, 'expense_date' => '2026-11-01', 'status' => 'pending',
            ]);

            return [$person, $expense->id];
        });
        AuditTrail::record($this->leader, 'assignment_created', $person, [], ['project_id' => $this->urban->id, 'role' => 'finance-admin']);

        $this->actingAs($this->leader)
            ->deleteJson("/api/program/assignments/{$person->id}/{$this->urban->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason' => 'A reason is required to remove someone from a project.']);
        $this->assertDatabaseHas('project_user', ['user_id' => $person->id, 'project_id' => $this->urban->id]);

        $this->actingAs($this->leader)
            ->deleteJson("/api/program/assignments/{$person->id}/{$this->urban->id}", ['reason' => 'Moved to another program'])
            ->assertOk();

        $this->assertDatabaseMissing('project_user', ['user_id' => $person->id, 'project_id' => $this->urban->id]);
        $removed = AuditTrail::where('action', 'assignment_removed')->get();
        $this->assertCount(1, $removed);
        $this->assertSame('Moved to another program', $removed[0]->reason);
        $this->assertSame(2, AuditTrail::count());

        // History stays linked to the person.
        $this->assertDatabaseHas('users', ['id' => $person->id, 'is_active' => true]);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'recorded_by' => $person->id, 'project_id' => $this->urban->id]);
        $this->assertDatabaseHas('audit_trails', ['action' => 'assignment_created', 'model_id' => $person->id]);
    }

    public function test_the_only_project_leader_of_an_ongoing_project_cannot_be_changed_or_removed(): void
    {
        [$only, $closedProject] = AuditLogger::muted(function () {
            $only = $this->person('project-leader', 'Dr. Aminah Macarambon', 'MSU-04182');
            $this->assign($only, $this->urban, 'project-leader');
            $closed = $this->project('MOST-RD-2024-009', 'Finished Study');
            $closed->update(['start_date' => '2024-01-01', 'end_date' => '2024-12-31']);
            $this->assign($only, $closed, 'project-leader');

            return [$only, $closed];
        });

        // Changing the account role would move the project row too.
        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$only->id}/role", ['role' => 'researcher'])
            ->assertStatus(422)
            ->assertJsonPath('message', "Enhancing Urban Mobility in Marawi City would be left without a Project Leader. Choose the new Project Leader in the project's set-up first; it replaces this one.");
        $this->assertSame('project-leader', $only->fresh()->primaryRole->slug);
        $this->actingAs($this->leader)
            ->deleteJson("/api/program/assignments/{$only->id}/{$this->urban->id}", ['reason' => 'Leaving'])
            ->assertStatus(422)
            ->assertJsonPath('message', "This project would be left without a Project Leader. Choose the new Project Leader in the project's set-up first; it replaces this one.");
        $this->assertSame(0, AuditTrail::count());

        // A finished project has no such guard.
        $this->actingAs($this->leader)
            ->deleteJson("/api/program/assignments/{$only->id}/{$closedProject->id}", ['reason' => 'Project closed'])
            ->assertOk();

        // With a second (active) Project Leader, the first can step down.
        AuditLogger::muted(function () {
            $second = $this->person('project-leader', 'Dr. Second Leader', 'MSU-04999');
            $this->assign($second, $this->urban, 'project-leader');
        });
        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$only->id}/role", ['role' => 'researcher'])
            ->assertOk();
        $this->assertSame('researcher', ProjectUser::where('user_id', $only->id)->where('project_id', $this->urban->id)->first()->role->slug);
        $this->assertSame(2, AuditTrail::count());
    }

    public function test_a_program_leaders_leftover_assignment_rows_can_be_removed(): void
    {
        AuditLogger::muted(fn () => ProjectUser::create([
            'project_id' => $this->urban->id, 'user_id' => $this->leader->id,
            'role_id' => Role::where('slug', 'program-leader')->value('id'), 'assigned_at' => now(),
        ]));

        $row = collect($this->list()['users'])->firstWhere('id', $this->leader->id);
        $this->assertTrue($row['program_wide']);
        $this->assertCount(1, $row['assignments']);

        $this->actingAs($this->leader)
            ->deleteJson("/api/program/assignments/{$this->leader->id}/{$this->urban->id}", ['reason' => 'Program Leaders do not need project assignments'])
            ->assertOk();

        $this->assertSame([], collect($this->list()['users'])->firstWhere('id', $this->leader->id)['assignments']);
        $this->assertSame(1, AuditTrail::where('action', 'assignment_removed')->count());
    }

    // ------------------------------------------------------------ helpers

    private function list(array $query = []): array
    {
        return $this->actingAs($this->leader)
            ->getJson('/api/program/users' . ($query ? '?' . http_build_query($query) : ''))
            ->assertOk()
            ->json();
    }

    private function names(array $query): array
    {
        return array_column($this->list($query)['users'], 'name');
    }

    private function person(string $slug, string $name, string $employeeId, ?string $position = 'Staff'): User
    {
        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace([' ', '.'], '', $name)) . '.' . uniqid() . '@msumain.edu.ph',
            'employee_id' => $employeeId,
            'password' => Hash::make('password123'),
            'position' => $position,
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function project(string $code, string $title): Project
    {
        $funder = FundingAgency::firstOrCreate(['acronym' => 'MOST-BARMM'], ['name' => 'MOST-BARMM']);

        return Project::create([
            'code' => $code,
            'title' => $title,
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);
    }

    private function assign(User $user, Project $project, string $roleSlug): void
    {
        ProjectUser::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role_id' => Role::where('slug', $roleSlug)->value('id'),
            'assigned_at' => now(),
        ]);
    }
}
