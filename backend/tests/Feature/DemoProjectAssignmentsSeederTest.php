<?php

namespace Tests\Feature;

use App\Models\AuditTrail;
use App\Models\FundingAgency;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DemoDatasetSeeder;
use Database\Seeders\DemoProjectAssignmentsSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\ProgramDashboardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoProjectAssignmentsSeederTest extends TestCase
{
    use RefreshDatabase;

    private const TARGETS = ['MOST-RD-2026-001', 'RP-2026-009', 'RP-2026-021'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00'));
        putenv('DEMO_ASSIGN_DRY_RUN');

        $this->seed(DemoUsersSeeder::class);
        $this->seed(DemoDatasetSeeder::class);
        $this->seed(ProgramDashboardSeeder::class);

        $this->scramble();
    }

    protected function tearDown(): void
    {
        putenv('DEMO_ASSIGN_DRY_RUN');
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_funder_reference_and_leader_change_and_a_second_run_changes_nothing(): void
    {
        $before = $this->snapshot();

        $this->seed(DemoProjectAssignmentsSeeder::class);
        $after = $this->snapshot();

        $expected = [
            'MOST-RD-2026-001' => ['MOST-BARMM', 'MOST-BARMM-GIA-2026-0012', 'project.leader@gmail.com'],
            'RP-2026-009' => ['DOST-PCAARRD', 'PCAARRD-GIA-2026-0457', 'project.leader2@gmail.com'],
            'RP-2026-021' => ['CHED', 'CHED-DARETO-2026-0118', 'project.leader4@gmail.com'],
        ];

        foreach ($before['projects'] as $id => $old) {
            $new = $after['projects'][$id];
            $code = $old['code'];

            if (isset($expected[$code])) {
                [$acronym, $reference, $leaderEmail] = $expected[$code];

                $this->assertSame($acronym, FundingAgency::find($new['funding_agency_id'])->acronym, $code);
                $this->assertSame($reference, $new['funder_reference'], $code);
                $this->assertSame([$leaderEmail], $this->leaderEmails($id), $code);

                // Every other column, updated_at included, is untouched.
                $this->assertEquals(
                    array_diff_key($old, array_flip(['funding_agency_id', 'funder_reference'])),
                    array_diff_key($new, array_flip(['funding_agency_id', 'funder_reference'])),
                    $code
                );
            } else {
                $this->assertEquals($old, $new, "Non-target project {$code} must be untouched");
            }
        }

        // Assignments of non-target projects and non-leader roles are untouched.
        $leaderRole = Role::where('slug', 'project-leader')->value('id');
        $targetIds = Project::whereIn('code', self::TARGETS)->pluck('id')->all();
        $keep = fn (array $rows) => collect($rows)
            ->reject(fn ($row) => in_array($row['project_id'], $targetIds) && $row['role_id'] == $leaderRole)
            ->values()->all();
        $this->assertEquals($keep($before['assignments']), $keep($after['assignments']));

        // No duplicates: one leader row per target, one agency per acronym, one user per email.
        foreach ($targetIds as $id) {
            $this->assertSame(1, DB::table('project_user')->where('project_id', $id)->where('role_id', $leaderRole)->count());
        }
        $this->assertSame(
            FundingAgency::count(),
            FundingAgency::query()->distinct()->count('acronym')
        );
        $this->assertSame(1, User::where('email', 'project.leader4@gmail.com')->count());
        $this->assertSame($before['users'] + 1, $after['users'], 'Only the missing demo leader is created');
        $this->assertSame($before['projects_count'], $after['projects_count'], 'No project is created');

        // The recreated leader cannot sign in with the demo password.
        $this->postJson('/api/login', ['email' => 'project.leader4@gmail.com', 'password' => 'password123'])
            ->assertStatus(422);

        // Changes are audited as System actions.
        $this->assertGreaterThan($before['audit'], $after['audit']);
        $this->assertTrue(AuditTrail::whereNull('user_id')->where('reason', 'DemoProjectAssignmentsSeeder')->exists());

        // Second run: nothing changes at all, not even the audit trail.
        $this->seed(DemoProjectAssignmentsSeeder::class);
        $this->assertEquals($after, $this->snapshot());
    }

    public function test_missing_project_is_skipped_not_created(): void
    {
        $this->assertNull(Project::where('code', 'RP-2025-031')->first());

        $this->seed(DemoProjectAssignmentsSeeder::class);

        $this->assertNull(Project::where('code', 'RP-2025-031')->first());
        $renamed = Project::where('code', 'RP-2025-031-ARCHIVED')->firstOrFail();
        $this->assertNull($renamed->funder_reference, 'A renamed project is not touched');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $before = $this->snapshot();

        putenv('DEMO_ASSIGN_DRY_RUN=1');
        $this->seed(DemoProjectAssignmentsSeeder::class);

        $this->assertEquals($before, $this->snapshot());
    }

    /**
     * Put the demo projects into a "production-like" state that needs fixing.
     */
    private function scramble(): void
    {
        $leaderRole = Role::where('slug', 'project-leader')->value('id');
        $other = FundingAgency::firstOrCreate(['acronym' => 'OTHER'], ['name' => 'Some other agency']);

        Project::whereIn('code', self::TARGETS)->update([
            'funding_agency_id' => $other->id,
            'funder_reference' => null,
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        // RP-2026-009: a different user holds the leader row (it must be repointed).
        $goat = Project::where('code', 'RP-2026-009')->value('id');
        $stranger = User::where('email', 'researcher@gmail.com')->value('id');
        DB::table('project_user')->where('project_id', $goat)->where('role_id', $leaderRole)
            ->update(['user_id' => $stranger]);

        // RP-2026-021: its demo leader account does not exist yet.
        $nutrition = Project::where('code', 'RP-2026-021')->value('id');
        $leader4 = User::where('email', 'project.leader4@gmail.com')->firstOrFail();
        DB::table('project_user')->where('user_id', $leader4->id)->delete();
        DB::table('audit_trails')->where('user_id', $leader4->id)->update(['user_id' => null]);
        $leader4->delete();
        $this->assertSame(0, DB::table('project_user')->where('project_id', $nutrition)->where('role_id', $leaderRole)->count());

        // RP-2025-031 "does not exist" in this database (renamed).
        Project::where('code', 'RP-2025-031')->update(['code' => 'RP-2025-031-ARCHIVED', 'funder_reference' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'projects' => DB::table('projects')->orderBy('id')->get()
                ->mapWithKeys(fn ($row) => [$row->id => (array) $row])->all(),
            'assignments' => DB::table('project_user')->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all(),
            'users' => User::count(),
            'agencies' => FundingAgency::orderBy('id')->get()->toArray(),
            'projects_count' => Project::count(),
            'audit' => AuditTrail::count(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function leaderEmails(int $projectId): array
    {
        return DB::table('project_user')
            ->join('roles', 'roles.id', '=', 'project_user.role_id')
            ->join('users', 'users.id', '=', 'project_user.user_id')
            ->where('project_user.project_id', $projectId)
            ->where('roles.slug', 'project-leader')
            ->pluck('users.email')
            ->all();
    }
}
