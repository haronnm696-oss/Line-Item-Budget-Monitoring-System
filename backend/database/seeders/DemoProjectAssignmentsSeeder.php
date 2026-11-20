<?php

namespace Database\Seeders;

use App\Services\ProjectLeaderRule;
use App\Services\AuditLogger;
use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\Concerns\AssignsDemoFunding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Production-safe backfill of the demo projects' funder, funder reference
 * number and Project Leader. It is deliberately NOT called by DatabaseSeeder.
 *
 *   php artisan db:seed --class=DemoProjectAssignmentsSeeder --force
 *   DEMO_ASSIGN_DRY_RUN=1 php artisan db:seed --class=DemoProjectAssignmentsSeeder --force
 *
 * Rules:
 * - Projects are found by code only and are never created; a missing code is
 *   skipped with a warning.
 * - Only funding_agency_id, funder_reference and the project-leader
 *   assignment change. No row is ever deleted or truncated.
 * - If another user holds the project-leader row, that one row is repointed
 *   to the demo leader (audited) instead of being deleted.
 * - Funders and missing demo leaders are created with firstOrCreate. New
 *   leaders get a random password (never the demo one); they sign in through
 *   "Forgot password".
 * - Idempotent: a second run finds nothing to change and writes nothing.
 */
class DemoProjectAssignmentsSeeder extends Seeder
{
    use AssignsDemoFunding;

    /** project code => key in AssignsDemoFunding::$demoAssignments */
    private const PROJECTS = [
        'MOST-RD-2026-001' => 'urban',
        'RP-2026-009' => 'goat',
        'RP-2026-021' => 'nutrition',
        'RP-2025-031' => 'weaving',
    ];

    /** Same profiles as DemoUsersSeeder, used only when the account is missing. */
    private const LEADER_PROFILES = [
        'project.leader@gmail.com' => ['Aminah', 'Macarambon', 'Dr. Aminah Macarambon', 'MSU-04182', 'Project Leader, Urban Mobility', 'College of Social Sciences and Humanities', 'Sociology'],
        'project.leader2@gmail.com' => ['Sittie Ayesah', 'Alonto', 'Dr. Sittie Ayesah Alonto', 'MSU-04183', 'Project Leader, Halal Goat Value Chain', 'College of Business Administration and Accountancy', 'Management'],
        'project.leader3@gmail.com' => ['Jamal', 'Macaraya', 'Engr. Jamal Macaraya', 'MSU-04184', 'Project Leader, Weaving Heritage Archive', 'College of Information and Computing Sciences', 'Information Sciences'],
        'project.leader4@gmail.com' => ['Norhaya', 'Dimakaling', 'Dr. Norhaya Dimakaling', 'MSU-04185', 'Project Leader, Community Nutrition', 'College of Health Sciences', 'Nursing'],
    ];

    private bool $dryRun = false;

    /**
     * Seeded rows are not logged by the audit observers; seeders write
     * their own audit history where it matters.
     */
    public function run(): void
    {
        AuditLogger::muted(fn () => $this->seed());
    }

    private function seed(): void
    {
        $flag = getenv('DEMO_ASSIGN_DRY_RUN');
        $this->dryRun = filter_var($flag !== false ? $flag : env('DEMO_ASSIGN_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN);

        $this->say('info', $this->dryRun
            ? 'DRY RUN: nothing will be written.'
            : 'Applying demo project assignments.');

        $role = Role::where('slug', 'project-leader')->first();
        if (! $role) {
            $this->say('warn', 'Role "project-leader" not found; leader assignments will be skipped.');
        }

        $changes = 0;
        foreach (self::PROJECTS as $code => $key) {
            $project = Project::where('code', $code)->first();
            if (! $project) {
                $this->say('warn', "Project {$code} not found; skipped.");
                Log::warning("DemoProjectAssignmentsSeeder: project {$code} not found; skipped.");
                continue;
            }

            $changes += $this->dryRun
                ? $this->assign($project, $key, $role)
                : DB::transaction(fn () => $this->assign($project, $key, $role));
        }

        $changes += $this->dryRun
            ? $this->repairBrokenDemoText()
            : DB::transaction(fn () => $this->repairBrokenDemoText());

        $this->say('info', ($this->dryRun ? 'Would change ' : 'Changed ') . $changes . ' value(s).');
    }

    /**
     * Demo titles whose dash was stored as "???" when the data was loaded
     * into production through a non-UTF-8 path. The original text cannot be
     * recovered automatically, so only these exact known values are repaired
     * ("???" -> "—"). Anything else, including user-entered text, is left
     * alone. Idempotent: once repaired, the broken values no longer match.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
     */
    public static function brokenDemoText(): array
    {
        $liquidations = array_map(fn (int $n) => "Liquidation Report ??? Tranche {$n}", [1, 2, 3, 4]);

        return [
            'project_tranches' => ['ProjectTranche', 'name', [
                'Tranche 1 ??? Project Mobilization',
                'Tranche 2 ??? Mid-year Milestone',
                'Tranche 3 ??? Field Validation',
                'Tranche 4 ??? Final Report & Closeout',
                'Tranche 1 ??? Mobilization',
                'Tranche 2 ??? Midyear Review',
                'Tranche 4 ??? Closeout',
            ]],
            'approval_requests' => ['ApprovalRequest', 'title', array_merge(
                ['Release Tranche 3 ??? Field Validation'],
                $liquidations
            )],
            'liquidations' => ['Liquidation', 'title', $liquidations],
        ];
    }

    private function repairBrokenDemoText(): int
    {
        $changes = 0;

        foreach (self::brokenDemoText() as $table => [$modelType, $column, $values]) {
            $rows = DB::table($table)->whereIn($column, $values)->orderBy('id')->get(['id', 'project_id', $column]);

            foreach ($rows as $row) {
                $broken = $row->{$column};
                $fixed = str_replace('???', '—', $broken);
                $this->say('line', "{$table} #{$row->id}: \"{$broken}\" -> \"{$fixed}\"");
                $changes++;

                if ($this->dryRun) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $fixed]);

                AuditTrail::create([
                    'user_id' => null,
                    'action' => 'updated',
                    'model_type' => $modelType,
                    'model_id' => $row->id,
                    'project_id' => $row->project_id,
                    'old_values' => [$column => $broken],
                    'new_values' => [$column => $fixed],
                    'reason' => 'DemoProjectAssignmentsSeeder: repaired a dash stored as "???"',
                    'ip_address' => null,
                    'created_at' => now(),
                ]);
            }
        }

        return $changes;
    }

    /**
     * @return int number of changed values for this project
     */
    private function assign(Project $project, string $key, ?Role $role): int
    {
        $target = $this->demoAssignments[$key];
        $changes = 0;

        // 1. Funder and funder reference.
        $funder = $this->funder($target['funder']);
        $old = [
            'funding_agency_id' => $project->funding_agency_id,
            'funder_reference' => $project->funder_reference,
        ];
        $new = [
            'funding_agency_id' => $funder?->id,
            'funder_reference' => $target['reference'],
        ];
        $diff = array_filter(
            ['funding_agency_id', 'funder_reference'],
            fn ($field) => (string) $old[$field] !== (string) $new[$field]
        );

        if ($diff) {
            $changes += count($diff);
            $parts = [];
            if (in_array('funding_agency_id', $diff, true)) {
                $parts[] = sprintf('funder %s -> %s%s', $this->funderLabel($old['funding_agency_id']), $target['funder'], $funder ? '' : ' (new agency)');
            }
            if (in_array('funder_reference', $diff, true)) {
                $parts[] = sprintf('reference %s -> %s', $old['funder_reference'] ?? 'NULL', $target['reference']);
            }
            $this->say('line', $project->code . ': ' . implode(', ', $parts));

            if (! $this->dryRun) {
                $funder ??= FundingAgency::firstOrCreate(
                    ['acronym' => $target['funder']],
                    ['name' => $this->demoFunders[$target['funder']]]
                );
                $new['funding_agency_id'] = $funder->id;

                // Only these two columns are written; updated_at is left as is.
                $project->timestamps = false;
                $project->forceFill($new)->save();
                $this->audit($project, 'demo_assignment_updated', $old, $new);
            }
        }

        // 2. Project Leader.
        if (! $role) {
            return $changes;
        }

        $leader = User::where('email', $target['leader'])->first();
        $current = ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('role_id', $role->id)
            ->orderBy('id')
            ->get();

        if ($leader && $current->contains('user_id', $leader->id)) {
            return $changes; // Already the leader.
        }

        // One active project per Project Leader: skip (with a warning) rather than break the rule.
        $otherLed = $leader ? ProjectLeaderRule::blockingProject($leader, $project) : null;
        if ($otherLed) {
            $this->say('warn', $project->code . ': ' . ProjectLeaderRule::message($leader, $otherLed) . ' Leader not changed.');

            return $changes;
        }

        $changes++;
        $replacing = $current->first();
        $this->say('line', sprintf(
            '%s: project leader %s -> %s%s',
            $project->code,
            $replacing ? (User::find($replacing->user_id)?->email ?? 'user #' . $replacing->user_id) : 'none',
            $target['leader'],
            $leader ? '' : ' (account will be created)'
        ));

        if ($this->dryRun) {
            return $changes;
        }

        $leader ??= $this->createLeader($target['leader'], $role);

        // The new leader may already hold another role on this project: turn
        // that row into the leader row instead of adding a second one (two
        // rows for one person made pages disagree about "my projects").
        $own = ProjectUser::query()->where('project_id', $project->id)->where('user_id', $leader->id)->orderBy('id')->first();
        if ($own) {
            $oldOwn = ['project_id' => $project->id, 'user_id' => $leader->id, 'role_id' => $own->role_id];
            $own->forceFill(['role_id' => $role->id])->save();
            $this->audit($project, 'assignment_updated', $oldOwn, [
                'project_id' => $project->id, 'user_id' => $leader->id, 'role_id' => $role->id,
            ]);
            if ($replacing) {
                $this->audit($project, 'assignment_removed', [
                    'project_id' => $project->id, 'user_id' => $replacing->user_id, 'role_id' => $role->id,
                ], []);
                $replacing->delete();
            }

            return $changes;
        }

        if ($replacing) {
            $oldLeader = ['project_id' => $project->id, 'user_id' => $replacing->user_id, 'role_id' => $role->id];
            $replacing->timestamps = false;
            $replacing->forceFill(['user_id' => $leader->id])->save();
            $this->audit($project, 'assignment_updated', $oldLeader, [
                'project_id' => $project->id, 'user_id' => $leader->id, 'role_id' => $role->id,
            ]);
        } else {
            ProjectUser::create([
                'project_id' => $project->id,
                'user_id' => $leader->id,
                'role_id' => $role->id,
                'assigned_at' => now(),
            ]);
            $this->audit($project, 'assignment_created', [], [
                'project_id' => $project->id, 'user_id' => $leader->id, 'role_id' => $role->id,
            ]);
        }

        return $changes;
    }

    private function funder(string $acronym): ?FundingAgency
    {
        return FundingAgency::where('acronym', $acronym)->first();
    }

    private function funderLabel($id): string
    {
        if (! $id) {
            return 'NULL';
        }

        $agency = FundingAgency::find($id);

        return $agency ? ($agency->acronym ?: $agency->name) : 'agency #' . $id;
    }

    private function createLeader(string $email, Role $role): User
    {
        [$first, $last, $name, $employeeId, $position, $college, $department] = self::LEADER_PROFILES[$email];

        $user = User::firstOrCreate(['email' => $email], [
            'first_name' => $first,
            'last_name' => $last,
            'name' => $name,
            // Only reuse the demo employee ID if nobody else holds it.
            'employee_id' => User::where('employee_id', $employeeId)->exists() ? null : $employeeId,
            'password' => Hash::make(Str::random(40)),
            'position' => $position,
            'college' => $college,
            'department' => $department,
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        if ($user->wasRecentlyCreated) {
            $this->audit($user, 'created', [], ['email' => $email, 'name' => $name, 'role_id' => $role->id]);
        }

        return $user;
    }

    private function audit($model, string $action, array $old, array $new): void
    {
        // user_id NULL is how the audit trail shows a "System" action.
        AuditTrail::create([
            'user_id' => null,
            'action' => $action,
            'model_type' => class_basename($model),
            'model_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'reason' => 'DemoProjectAssignmentsSeeder',
            'ip_address' => null,
            'created_at' => now(),
        ]);
    }

    private function say(string $level, string $message): void
    {
        if ($this->command) {
            $this->command->{$level}($message);
        }
    }
}
