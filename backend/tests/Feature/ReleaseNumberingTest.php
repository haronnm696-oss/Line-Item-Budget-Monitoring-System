<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ProjectTranche;
use App\Services\AuditLogger;
use App\Support\ReleaseWording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * A release's number ("Release 2") is stored and never changes: adding an
 * earlier release, moving a date or removing a release renumbers nothing,
 * and a removed number is not given again. Lists stay in planned-date order.
 */
class ReleaseNumberingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
        // The MOA grant is the whole budget, so releases are capped at 1,000,000 (not the 500,000 line item).
        AuditLogger::muted(fn () => $this->project->update(['funding_agency_amount' => 1000000]));
    }

    public function test_adding_a_release_with_an_earlier_date_does_not_renumber_existing_ones(): void
    {
        $december = $this->addRelease(100000, '2026-12-01');
        $march = $this->addRelease(100000, '2027-03-01');
        $this->assertSame([1, 2], [$december->release_number, $march->release_number]);

        // Planned before both: it gets the next number, 3.
        $october = $this->addRelease(50000, '2026-10-01');
        $this->assertSame(
            [1, 2, 3],
            [$december->fresh()->release_number, $march->fresh()->release_number, $october->release_number]
        );

        // Listed by planned date, labelled by the stored number.
        $rows = $this->actingAs($this->programLeader)
            ->getJson("/api/program/projects/{$this->project->id}")->assertOk()->json('tranches');
        $this->assertSame(['Release 3', 'Release 1', 'Release 2'], array_column($rows, 'name'));
    }

    public function test_editing_a_date_does_not_renumber(): void
    {
        $first = $this->addRelease(100000, '2026-12-01');
        $second = $this->addRelease(100000, '2027-03-01');

        $this->actingAs($this->programLeader)
            ->patchJson("/api/program/tranches/{$second->id}", ['planned_release_date' => '2026-11-01'])
            ->assertOk()->assertJsonPath('tranche.name', 'Release 2');
        $this->patchJson("/api/program/tranches/{$first->id}", ['planned_release_date' => '2027-06-01', 'name' => ''])
            ->assertOk()->assertJsonPath('tranche.name', 'Release 1');

        $this->assertSame([1, 2], [$first->fresh()->release_number, $second->fresh()->release_number]);
    }

    public function test_removing_a_release_renumbers_nothing_and_its_number_is_not_reused(): void
    {
        $first = $this->addRelease(100000, '2026-12-01');
        $second = $this->addRelease(100000, '2027-01-01');
        $third = $this->addRelease(100000, '2027-02-01');

        $this->actingAs($this->programLeader)->deleteJson("/api/program/tranches/{$third->id}")->assertOk();
        $this->deleteJson("/api/program/tranches/{$first->id}")->assertOk();
        $this->assertSame(2, $second->fresh()->release_number);

        // Release 3 was the highest given; the next one is 4, not 3.
        $this->assertSame(4, $this->addRelease(100000, '2027-03-01')->release_number);
    }

    public function test_a_released_release_keeps_its_number_in_the_audit_trail_my_requests_and_the_csv(): void
    {
        $later = $this->addRelease(300000, '2027-03-01');   // Release 1
        $this->addRelease(200000, '2026-12-01');             // Release 2, planned earlier

        $id = $this->actingAs($this->projectLeader)
            ->postJson("/api/projects/{$this->project->id}/budget-requests", [
                'type' => 'fund_release', 'project_tranche_id' => $later->id, 'justification' => 'Fieldwork in March.',
            ])->assertCreated()->assertJsonPath('budget_request.title', 'Release 1')->json('budget_request.id');
        $this->actingAs($this->programLeader)
            ->postJson("/api/program/approvals/{$id}/decision", ['decision' => 'approved'])->assertOk();
        $this->assertSame('released', $later->fresh()->status);

        // Another release, planned earliest, added after the payout.
        $this->addRelease(100000, '2026-11-01');             // Release 3
        $this->assertSame(1, $later->fresh()->release_number);

        // My requests (the maker's list).
        $row = collect($this->actingAs($this->projectLeader)
            ->getJson("/api/projects/{$this->project->id}/budget-requests")->assertOk()->json('budget_requests'))
            ->firstWhere('id', $id);
        $this->assertSame(['Release 1', 'Fund Release'], [$row['title'], $row['type_label']]);

        // Audit trail: the release and the approved request.
        $entries = collect($this->actingAs($this->programLeader)->getJson('/api/program/audit')->assertOk()->json('entries'));
        $releaseEntry = $entries->first(fn ($e) => $e['record']['type'] === 'Release · Released');
        $this->assertSame('Release 1', $releaseEntry['record']['label']);
        $approved = $entries->first(fn ($e) => $e['action']['code'] === 'approved'
            && str_starts_with($e['record']['type'], 'Approval request'));
        $this->assertSame('Release 1', $approved['record']['label']);
        $this->assertStringNotContainsStringIgnoringCase('tranche', json_encode($entries->all()));

        // Form C CSV: one row per release, in planned-date order, with its stored number.
        $response = $this->actingAs($this->programLeader)->get("/api/projects/{$this->project->id}/reports/form-c")->assertOk();
        $csv = $response->getContent();
        $this->assertStringContainsString('Release,Milestone,Amount', $csv);
        $lines = array_values(array_filter(preg_split('/\r?\n/', $csv), fn ($line) => preg_match('/^"?Release \d/', $line)));
        $this->assertSame(
            ['Release 3', 'Release 2', 'Release 1'],
            array_map(fn ($line) => trim(explode(',', $line)[0], '"'), $lines)
        );
        $this->assertStringContainsString('Released', $lines[2]);
        $this->assertStringNotContainsStringIgnoringCase('tranche', $csv);
    }

    public function test_old_titles_read_the_stored_number_of_their_release(): void
    {
        $release = AuditLogger::muted(fn () => ProjectTranche::create([
            'project_id' => $this->project->id, 'sequence' => 2, 'release_number' => 5, 'name' => 'Second tranche',
            'amount' => 100000, 'status' => 'upcoming', 'planned_release_date' => '2026-12-01',
        ]));
        $approval = AuditLogger::muted(fn () => ApprovalRequest::create([
            'project_id' => $this->project->id, 'requested_by' => $this->projectLeader->id, 'type' => 'disbursement',
            'title' => 'Fund release: Second tranche', 'project_tranche_id' => $release->id, 'status' => 'pending',
            'justification' => 'Needed.',
        ]));

        $this->assertSame('Release 5', $release->releaseLabel());
        $this->assertSame('Release 5', $approval->displayTitle());
        $this->assertSame('Liquidation report — Release 5', ReleaseWording::apply('Liquidation report — Tranche 2', $release));
        // A number given by hand still moves the project's counter.
        $this->assertSame(6, $this->addRelease(1000, '2027-01-01')->release_number);
    }

    public function test_the_migration_backfills_once_by_planned_date_then_id(): void
    {
        $numbered = $this->addRelease(1000, '2026-12-01'); // already Release 1, kept
        $ids = [];
        foreach ([['2027-02-01', 2], ['2026-11-01', 3], [null, 4], ['2026-11-01', 5]] as [$date, $sequence]) {
            $ids[] = DB::table('project_tranches')->insertGetId([
                'project_id' => $this->project->id, 'sequence' => $sequence, 'name' => 'Tranche ' . $sequence,
                'amount' => 1000, 'status' => 'upcoming', 'planned_release_date' => $date,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $migration = require database_path('migrations/2026_11_22_000001_add_release_number_to_project_tranches.php');
        $migration->backfill();
        $numbers = fn () => DB::table('project_tranches')->whereIn('id', $ids)->orderBy('id')
            ->pluck('release_number')->map(fn ($n) => (int) $n)->all();

        // After Release 1: 2026-11-01 (lower id) = 2, 2026-11-01 = 3, 2027-02-01 = 4, undated = 5.
        $this->assertSame([4, 2, 5, 3], $numbers());
        $this->assertSame(1, $numbered->fresh()->release_number);
        $this->assertSame(5, (int) DB::table('projects')->where('id', $this->project->id)->value('last_release_number'));

        // Running it again changes nothing.
        DB::table('project_tranches')->where('id', $ids[0])->update(['planned_release_date' => '2026-01-01']);
        $migration->backfill();
        $this->assertSame([4, 2, 5, 3], $numbers());
    }

    private function addRelease(float $amount, string $date): ProjectTranche
    {
        $id = $this->actingAs($this->programLeader)
            ->postJson("/api/program/projects/{$this->project->id}/tranches", ['amount' => $amount, 'planned_release_date' => $date])
            ->assertCreated()->json('tranche.id');

        return ProjectTranche::findOrFail($id);
    }
}
