<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Payroll;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class ProgramAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $leader;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-14 02:30:00', 'UTC'));

        // Fixtures are built without automatic entries so each test sees only
        // the entries it causes.
        [$this->leader, $this->project] = AuditLogger::muted(fn () => [
            $this->userWithRole('program-leader', 'Dr. Rahana Bayabao'),
            $this->makeProject('Meranaw Weaving Heritage Digital Archive'),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------- readable output

    public function test_an_approved_request_reads_as_text_with_names_resolved_and_technical_fields_hidden(): void
    {
        $approval = AuditLogger::muted(function () {
            $from = $this->lineItem('Professional Services', 500000);
            $to = $this->lineItem('Traveling Expenses', 100000);

            return $this->approval('budget_realignment', 'Realign to field travel', [
                'from_lib_category_id' => $from->id,
                'to_lib_category_id' => $to->id,
                'amount' => 40000,
            ]);
        });

        $this->actingAs($this->leader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved', 'remarks' => 'Within the LIB ceiling.'])
            ->assertOk();

        $row = collect($this->feed()['entries'])->firstWhere('action.code', 'approved');

        $this->assertSame('Request approved', $row['action']['label']);
        $this->assertSame('success', $row['action']['tone']);
        $this->assertSame('Dr. Rahana Bayabao', $row['actor']['name']);
        $this->assertSame('Program Leader', $row['actor']['role']);
        $this->assertSame('Realign to field travel', $row['record']['label']);
        $this->assertSame('Approval request · Realignment', $row['record']['type']);
        $this->assertSame(['kind' => 'approval', 'tab' => 'approved', 'project_id' => $this->project->id], $row['record']['link']);
        $this->assertSame(['id' => $this->project->id, 'title' => 'Meranaw Weaving Heritage Digital Archive'], $row['project']);

        $changes = collect($row['changes'])->keyBy('label');
        $this->assertSame(['label' => 'Status', 'old' => 'Pending', 'new' => 'Approved'], $changes['Status']);
        $this->assertSame('Dr. Rahana Bayabao', $changes['Reviewed by']['new']);
        $this->assertSame('Within the LIB ceiling.', $changes['Remark']['new']);
        foreach ($changes->keys() as $label) {
            $this->assertStringNotContainsStringIgnoringCase('reviewed at', $label);
            $this->assertStringNotContainsStringIgnoringCase('updated at', $label);
        }
        $this->assertStringNotContainsString('ApprovalRequest #', json_encode($row));
    }

    public function test_a_budget_change_lists_each_line_item_and_amount(): void
    {
        [$from, $to, $approval] = AuditLogger::muted(function () {
            $from = $this->lineItem('Professional Services', 500000);
            $to = $this->lineItem('Traveling Expenses', 100000);

            return [$from, $to, $this->approval('budget_realignment', 'Move to travel', [
                'from_lib_category_id' => $from->id,
                'to_lib_category_id' => $to->id,
                'amount' => 40000,
            ])];
        });

        $this->actingAs($this->leader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'approved', 'remarks' => 'Field survey moved.'])
            ->assertOk();

        $row = collect($this->feed()['entries'])->firstWhere('action.code', 'budget_changed');

        $this->assertSame('Budget changed', $row['action']['label']);
        $this->assertSame([
            ['label' => 'Professional Services', 'old' => null, 'new' => '−₱40,000.00'],
            ['label' => 'Traveling Expenses', 'old' => null, 'new' => '+₱40,000.00'],
        ], $row['changes']);
        $this->assertSame('Field survey moved.', $row['reason']);
        // The realignment itself is not logged a second time as two line-item edits.
        $this->assertSame(0, AuditTrail::where('model_type', 'LibCategory')->count());
    }

    public function test_a_rejected_realignment_logs_no_budget_change(): void
    {
        $approval = AuditLogger::muted(function () {
            $from = $this->lineItem('Professional Services', 500000);
            $to = $this->lineItem('Traveling Expenses', 100000);

            return $this->approval('budget_realignment', 'Move to travel', [
                'from_lib_category_id' => $from->id,
                'to_lib_category_id' => $to->id,
                'amount' => 40000,
            ]);
        });

        $this->actingAs($this->leader)
            ->postJson("/api/program/approvals/{$approval->id}/decision", ['decision' => 'rejected', 'remarks' => 'Not justified.'])
            ->assertOk();

        $this->assertSame(0, AuditTrail::where('action', 'budget_changed')->count());
        $row = collect($this->feed()['entries'])->firstWhere('action.code', 'returned');
        $this->assertSame('Request returned', $row['action']['label']);
        $this->assertNotContains('Liquidation', array_column($row['changes'], 'label'));

        // An entry written by older code for a rejected realignment shows no amounts.
        AuditTrail::record($this->leader, 'budget_changed', $approval, ['status' => 'pending'], [
            'status' => 'rejected',
            'payload' => $approval->payload,
        ], 'Not justified.');
        $legacy = collect($this->feed()['entries'])->firstWhere('action.code', 'budget_changed');
        $this->assertSame([], $legacy['changes']);
        $this->assertSame('Not justified.', $legacy['reason']);
    }

    public function test_a_rejected_registration_names_the_person_and_shows_the_reason_once(): void
    {
        $applicant = AuditLogger::muted(fn () => User::create([
            'name' => 'Amina Pangandaman',
            'email' => 'amina@msumain.edu.ph',
            'password' => Hash::make('secret-password-1'),
            'is_active' => false,
        ]));

        $this->actingAs($this->leader)
            ->postJson("/api/program/registrations/{$applicant->id}/decision", ['decision' => 'rejected', 'remarks' => 'Invalid email address'])
            ->assertOk();

        $row = collect($this->feed()['entries'])->firstWhere('action.code', 'rejected');

        $this->assertSame('Registration rejected', $row['action']['label']);
        $this->assertSame('danger', $row['action']['tone']);
        $this->assertSame('Amina Pangandaman', $row['record']['label']);
        $this->assertSame(['kind' => 'user', 'user_id' => $applicant->id], $row['record']['link']);
        $this->assertSame('Invalid email address', $row['reason']);
        $this->assertSame(1, substr_count(json_encode($row), 'Invalid email address'));
        $this->assertStringNotContainsString('User #', json_encode($row));
    }

    public function test_role_status_and_assignment_changes_use_readable_labels(): void
    {
        $person = AuditLogger::muted(fn () => $this->userWithRole('researcher', 'Faisal Sarip'));

        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}/role", ['role' => 'project-leader', 'reason' => 'Now leads a project'])
            ->assertOk();
        $this->actingAs($this->leader)
            ->postJson('/api/program/assignments', ['user_id' => $person->id, 'project_id' => $this->project->id])
            ->assertSuccessful();
        $this->actingAs($this->leader)
            ->patchJson("/api/program/users/{$person->id}/status", ['is_active' => false, 'reason' => 'On leave'])
            ->assertOk();

        $rows = collect($this->feed()['entries'])->keyBy('action.code');

        $this->assertSame('Role changed', $rows['role_changed']['action']['label']);
        $this->assertSame([['label' => 'Role', 'old' => 'Researcher', 'new' => 'Project Leader']], $rows['role_changed']['changes']);
        $this->assertSame('Now leads a project', $rows['role_changed']['reason']);

        $this->assertSame([['label' => 'Account', 'old' => 'Active', 'new' => 'Inactive']], $rows['deactivated']['changes']);

        $this->assertSame('Assigned to project', $rows['assignment_created']['action']['label']);
        $this->assertSame('Faisal Sarip', $rows['assignment_created']['record']['label']);
        $this->assertSame('Meranaw Weaving Heritage Digital Archive', $rows['assignment_created']['project']['title']);
        // The role on the project is the account role (Project Leader since the change above).
        $this->assertSame([['label' => 'Role', 'old' => null, 'new' => 'Project Leader']], $rows['assignment_created']['changes']);
    }

    public function test_record_edits_show_only_changed_fields_with_money_and_names(): void
    {
        $this->actingAs($this->leader);
        $item = $this->lineItem('Supplies & Materials', 50000);
        $expense = Expense::create([
            'project_id' => $this->project->id,
            'lib_category_id' => $item->id,
            'recorded_by' => $this->leader->id,
            'reference_no' => 'WEAVE-2026-011',
            'description' => 'Loom repair parts',
            'amount' => 12500,
            'expense_date' => '2026-11-10',
            'status' => 'pending',
        ]);
        $expense->update(['amount' => 13750.5]);
        $item->update(['allocated_amount' => 60000]);
        $expense->delete();

        $rows = collect($this->feed()['entries']);

        $edited = $rows->first(fn ($r) => $r['action']['label'] === 'Expense edited');
        $this->assertSame('WEAVE-2026-011 · Loom repair parts', $edited['record']['label']);
        $this->assertSame('Expense · Supplies & Materials', $edited['record']['type']);
        $this->assertSame([['label' => 'Amount', 'old' => '₱12,500.00', 'new' => '₱13,750.50']], $edited['changes']);

        $added = $rows->first(fn ($r) => $r['action']['label'] === 'Expense added');
        $addedChanges = collect($added['changes'])->keyBy('label');
        $this->assertSame('Supplies & Materials', $addedChanges['Line item']['new']);
        $this->assertSame('Dr. Rahana Bayabao', $addedChanges['Recorded by']['new']);
        $this->assertSame('Nov 10, 2026', $addedChanges['Expense date']['new']);
        $this->assertFalse($addedChanges->has('Project'), 'project_id repeats the Project column');

        // allocated_amount alone changes the funder share (allocated = counterpart + funder).
        $this->assertSame([
            ['label' => 'Allocated', 'old' => '₱50,000.00', 'new' => '₱60,000.00'],
            ['label' => 'Funder share', 'old' => '₱50,000.00', 'new' => '₱60,000.00'],
        ], $rows->first(fn ($r) => $r['action']['label'] === 'Line item edited')['changes']);

        $deleted = $rows->first(fn ($r) => $r['action']['label'] === 'Expense deleted');
        $this->assertSame('danger', $deleted['action']['tone']);
        $this->assertSame('WEAVE-2026-011 · Loom repair parts', $deleted['record']['label']);
    }

    public function test_passwords_and_tokens_are_never_stored_or_shown(): void
    {
        $this->actingAs($this->leader);
        $person = $this->userWithRole('researcher', 'Token Holder');
        $person->update(['password' => Hash::make('another-secret-9'), 'position' => 'Senior Researcher']);

        foreach (AuditTrail::all() as $entry) {
            $raw = json_encode([$entry->old_values, $entry->new_values]);
            $this->assertStringNotContainsStringIgnoringCase('password', $raw);
            $this->assertStringNotContainsStringIgnoringCase('remember_token', $raw);
        }

        $this->assertStringNotContainsStringIgnoringCase('password', json_encode($this->feed()['entries']));
        $this->assertSame([['label' => 'Position', 'old' => '—', 'new' => 'Senior Researcher']],
            collect($this->feed()['entries'])->firstWhere('action.label', 'User account edited')['changes']);
    }

    // ----------------------------------------------------- time and filters

    public function test_timestamps_are_returned_with_an_explicit_offset(): void
    {
        AuditTrail::record($this->leader, 'created', $this->project, [], ['title' => 'x']);

        $created = $this->feed()['entries'][0]['created_at'];

        $this->assertSame('2026-11-14T02:30:00+00:00', $created);
        $this->assertSame('2026-11-14 10:30', Carbon::parse($created)->setTimezone('Asia/Manila')->format('Y-m-d H:i'));
        $this->assertSame('Asia/Manila', $this->feed()['meta']['timezone']);
    }

    public function test_filters_by_action_project_user_and_philippine_date_range(): void
    {
        [$other, $otherProject] = AuditLogger::muted(fn () => [
            $this->userWithRole('finance-admin', 'Norjannah Haron'),
            $this->makeProject('Halal Goat Value Chain'),
        ]);

        Carbon::setTestNow('2026-11-09 17:00:00'); // Nov 10, 01:00 in Manila
        AuditTrail::record($this->leader, 'approved', $this->project, [], ['status' => 'approved']);
        Carbon::setTestNow('2026-11-12 03:00:00');
        AuditTrail::record($other, 'rejected', $otherProject, [], ['status' => 'rejected'], 'Missing receipts');
        Carbon::setTestNow('2026-11-14 02:30:00');
        AuditTrail::record($this->leader, 'created', $otherProject, [], ['title' => 'Halal Goat Value Chain']);

        $this->assertSame(['approved'], $this->codes(['action' => 'approved']));
        // ?project= is what the Projects and Approvals pages link with.
        $this->assertSame(['approved'], $this->codes(['project' => $this->project->id]));
        $this->assertSame(['rejected'], $this->codes(['user' => $other->id]));
        $this->assertSame(['approved'], $this->codes(['from' => '2026-11-10', 'to' => '2026-11-10']));
        $this->assertSame(['created', 'rejected'], $this->codes(['from' => '2026-11-11']));
        $this->assertSame(['rejected'], $this->codes(['action' => 'rejected', 'project' => $otherProject->id, 'user' => $other->id]));

        $options = $this->feed()['options'];
        $this->assertEqualsCanonicalizing(['Dr. Rahana Bayabao', 'Norjannah Haron'], array_column($options['users'], 'name'));
        $this->assertContains(['value' => 'rejected', 'label' => 'Rejected'], $options['actions']);
    }

    public function test_pages_of_fifty_newest_first_with_a_total(): void
    {
        foreach (range(1, 60) as $i) {
            Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00')->addMinutes($i));
            AuditTrail::record($this->leader, 'updated', $this->project, ['title' => "Old {$i}"], ['title' => "New {$i}"]);
        }

        $first = $this->feed();
        $this->assertCount(50, $first['entries']);
        $this->assertSame(60, $first['meta']['total']);
        $this->assertTrue($first['meta']['has_more']);
        $this->assertSame('New 60', $first['entries'][0]['changes'][0]['new']);

        $second = $this->feed(['page' => 2]);
        $this->assertCount(10, $second['entries']);
        $this->assertFalse($second['meta']['has_more']);
        $this->assertSame('New 1', $second['entries'][9]['changes'][0]['new']);
    }

    public function test_presenting_a_page_does_not_grow_queries_with_the_number_of_entries(): void
    {
        $count = function (int $entries) {
            AuditLogger::muted(function () use ($entries) {
                DB::table('audit_trails')->delete();
                foreach (range(1, $entries) as $i) {
                    $item = $this->lineItem("Line {$i}", 1000);
                    AuditTrail::record($this->leader, 'updated', $item, ['allocated_amount' => 1000, 'project_id' => $this->project->id], ['allocated_amount' => 2000, 'project_id' => $this->project->id]);
                }
            });
            $this->feed(); // warm-up: the role middleware caches the actor's role
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->feed();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(3), $count(30));
    }

    // ------------------------------------------------------ complete log (6)

    public function test_add_edit_and_delete_are_logged_for_every_audited_record_type(): void
    {
        $this->actingAs($this->leader);
        $person = $this->userWithRole('researcher', 'Payroll Person');
        $item = $this->lineItem('Honoraria', 100000);

        $records = [
            'Project' => $this->makeProject('Logged project'),
            'LibCategory' => $item,
            'ProjectTranche' => ProjectTranche::create([
                'project_id' => $this->project->id, 'sequence' => 1, 'name' => 'Tranche 1 — Mobilization',
                'amount' => 250000, 'percentage' => 25, 'status' => 'upcoming',
            ]),
            'Expense' => Expense::create([
                'project_id' => $this->project->id, 'lib_category_id' => $item->id, 'recorded_by' => $this->leader->id,
                'reference_no' => 'R-1', 'description' => 'Item', 'amount' => 100, 'expense_date' => '2026-11-01', 'status' => 'pending',
            ]),
            'Payroll' => Payroll::create([
                'project_id' => $this->project->id, 'lib_category_id' => $item->id, 'personnel_id' => $person->id,
                'computed_by' => $this->leader->id, 'pay_month' => 11, 'pay_year' => 2026, 'basic_rate' => 20000,
                'gross_pay' => 20000, 'total_deductions' => 1000, 'net_pay' => 19000, 'status' => 'draft',
            ]),
            'User' => $person,
            'ProjectUser' => ProjectUser::create([
                'project_id' => $this->project->id, 'user_id' => $person->id,
                'role_id' => Role::where('slug', 'researcher')->value('id'), 'assigned_at' => now(),
            ]),
        ];

        $edits = [
            'Project' => ['title' => 'Logged project (renamed)'],
            'LibCategory' => ['allocated_amount' => 120000, 'funder_amount' => 120000],
            'ProjectTranche' => ['status' => 'due'],
            'Expense' => ['amount' => 150],
            'Payroll' => ['status' => 'approved'],
            'User' => ['position' => 'Lead Researcher'],
            'ProjectUser' => ['role_id' => Role::where('slug', 'research-assistant')->value('id')],
        ];

        foreach ($records as $type => $model) {
            $model->update($edits[$type]);
        }
        foreach (array_reverse($records, true) as $model) {
            $model->delete();
        }

        foreach (array_keys($records) as $type) {
            foreach (['created', 'updated', 'deleted'] as $action) {
                $entry = AuditTrail::where('model_type', $type)->where('action', $action)->first();
                $this->assertNotNull($entry, "{$type} {$action} was not logged");
                $this->assertSame($this->leader->id, (int) $entry->user_id, "{$type} {$action} actor");
                if ($type !== 'User') {
                    $this->assertNotNull($entry->project_id, "{$type} {$action} project");
                }
            }
            $updated = AuditTrail::where('model_type', $type)->where('action', 'updated')->first();
            $this->assertSame(array_keys($edits[$type]), array_keys($updated->new_values), "{$type}: only changed fields");
        }
    }

    public function test_approve_and_reject_are_logged_for_requests_and_registrations(): void
    {
        [$a, $b, $c, $d] = AuditLogger::muted(fn () => [
            $this->approval('disbursement', 'Release Tranche 2'),
            $this->approval('disbursement', 'Release Tranche 3'),
            User::create(['name' => 'Applicant One', 'email' => 'one@msumain.edu.ph', 'password' => Hash::make('x-password-1'), 'is_active' => false]),
            User::create(['name' => 'Applicant Two', 'email' => 'two@msumain.edu.ph', 'password' => Hash::make('x-password-2'), 'is_active' => false]),
        ]);

        $this->actingAs($this->leader);
        $this->postJson("/api/program/approvals/{$a->id}/decision", ['decision' => 'approved'])->assertOk();
        $this->postJson("/api/program/approvals/{$b->id}/decision", ['decision' => 'rejected', 'remarks' => 'Incomplete'])->assertOk();
        $this->postJson("/api/program/registrations/{$c->id}/decision", ['decision' => 'approved', 'role' => 'researcher'])->assertOk();
        $this->postJson("/api/program/registrations/{$d->id}/decision", ['decision' => 'rejected', 'remarks' => 'Unknown applicant'])->assertOk();

        foreach ([[$a, 'approved'], [$b, 'returned']] as [$approval, $action]) {
            $this->assertDatabaseHas('audit_trails', ['model_type' => 'ApprovalRequest', 'model_id' => $approval->id, 'action' => $action, 'user_id' => $this->leader->id, 'project_id' => $this->project->id]);
        }
        foreach ([[$c, 'approved'], [$d, 'rejected']] as [$user, $action]) {
            $this->assertDatabaseHas('audit_trails', ['model_type' => 'User', 'model_id' => $user->id, 'action' => $action, 'user_id' => $this->leader->id]);
        }
        // Each decision is logged once, not again as a generic "edited" entry.
        $this->assertSame(0, AuditTrail::where('action', 'updated')->whereIn('model_type', ['User', 'ProjectTranche'])->count());
    }

    public function test_audit_entries_are_read_only(): void
    {
        $entry = AuditTrail::record($this->leader, 'created', $this->project, [], ['title' => 'x']);

        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'audit'))
            ->flatMap(fn ($route) => $route->methods())
            ->unique()
            ->values()
            ->all();
        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $writeRoutes);

        foreach (['patchJson', 'putJson', 'deleteJson'] as $method) {
            $status = $this->actingAs($this->leader)->{$method}('/api/program/audit/' . $entry->id, ['action' => 'x'])->status();
            $this->assertContains($status, [404, 405], "{$method} must not reach an audit entry");
            $status = $this->actingAs($this->leader)->{$method}('/api/audit/' . $entry->id, ['action' => 'x'])->status();
            $this->assertContains($status, [404, 405], "{$method} must not reach an audit entry");
        }

        try {
            $entry->update(['action' => 'tampered']);
            $this->fail('An audit entry was updated.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit entries are read-only.', $exception->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('An audit entry was deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit entries are read-only.', $exception->getMessage());
        }

        $this->assertDatabaseHas('audit_trails', ['id' => $entry->id, 'action' => 'created']);
    }

    public function test_only_the_program_leader_can_read_the_program_audit_feed(): void
    {
        $researcher = AuditLogger::muted(fn () => $this->userWithRole('researcher', 'Some Researcher'));

        $this->actingAs($researcher)->getJson('/api/program/audit')->assertForbidden();
    }

    public function test_old_entries_read_the_release_by_its_stored_number(): void
    {
        // As the backfill numbered them: by planned date, not by the old names.
        [$late, $early] = AuditLogger::muted(fn () => [
            ProjectTranche::create([
                'project_id' => $this->project->id, 'sequence' => 1, 'release_number' => 2, 'name' => 'First tranche',
                'amount' => 100000, 'status' => 'upcoming', 'planned_release_date' => '2027-03-01',
            ]),
            ProjectTranche::create([
                'project_id' => $this->project->id, 'sequence' => 2, 'release_number' => 1, 'name' => 'Second tranche',
                'amount' => 50000, 'status' => 'upcoming', 'planned_release_date' => '2026-12-01',
            ]),
        ]);
        // Entries written before the wording change, with the old title and name.
        $approval = AuditLogger::muted(fn () => tap($this->approval('disbursement', 'Fund release: Second tranche'))
            ->update(['project_tranche_id' => $early->id]));
        AuditLogger::write('created', $approval, [], $approval->only(['title', 'project_tranche_id', 'amount']), $this->leader);
        AuditLogger::write('created', $late, [], ['name' => 'First tranche', 'sequence' => 1, 'amount' => 100000], $this->leader);

        $rows = collect($this->feed()['entries']);
        $release = $rows->firstWhere('record.type', 'Release · Upcoming');
        $request = $rows->firstWhere('record.type', 'Approval request · Fund release');

        $this->assertSame('Release 2', $release['record']['label']);
        $this->assertSame('Release 1', $request['record']['label']);
        $changes = collect($request['changes'])->keyBy('label');
        $this->assertSame('Release 1', $changes['Release']['new']);
        // "Second tranche" in the old title is the release it names: Release 1.
        $this->assertSame('Fund release: Release 1', $changes['Title']['new']);
        $this->assertStringNotContainsStringIgnoringCase('tranche', json_encode($rows->all()));
    }

    // -------------------------------------------------------------- helpers

    private function feed(array $query = []): array
    {
        return $this->actingAs($this->leader)
            ->getJson('/api/program/audit' . ($query ? '?' . http_build_query($query) : ''))
            ->assertOk()
            ->json();
    }

    private function codes(array $query): array
    {
        $codes = array_column(array_column($this->feed($query)['entries'], 'action'), 'code');
        sort($codes);

        return $codes;
    }

    private function makeProject(string $title): Project
    {
        $funder = FundingAgency::firstOrCreate(['acronym' => 'MOST-BARMM'], ['name' => 'MOST-BARMM']);

        return Project::create([
            'code' => 'AUD-' . uniqid(),
            'title' => $title,
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 1000000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ongoing',
        ]);
    }

    private function lineItem(string $name, float $amount): LibCategory
    {
        return LibCategory::create([
            'project_id' => $this->project->id,
            'category_type' => 'MOOE',
            'line_item_name' => $name,
            'allocated_amount' => $amount,
        ]);
    }

    private function approval(string $type, string $title, array $payload = []): ApprovalRequest
    {
        return ApprovalRequest::create([
            'project_id' => $this->project->id,
            'type' => $type,
            'title' => $title,
            'justification' => 'Needed to keep the project on schedule.',
            'amount' => 40000,
            'payload' => $payload ?: null,
            'requested_by' => $this->userWithRole('project-leader', 'Requester ' . uniqid())->id,
            'status' => 'pending',
        ]);
    }

    private function userWithRole(string $slug, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => $slug . '.' . uniqid() . '@msumain.edu.ph',
            'password' => Hash::make('password123'),
            'role_id' => Role::where('slug', $slug)->value('id'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
