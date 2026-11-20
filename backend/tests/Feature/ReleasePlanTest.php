<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Liquidation;
use App\Models\ProjectTranche;
use App\Services\AuditLogger;
use App\Services\ProjectSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Releases as parts of the funder's grant (MOA): the funder total caps the
 * planned releases, "Split 70% / 30%" creates the first two exactly, and a
 * fund release request warns (never blocks) while an earlier released
 * release has no approved liquidation.
 */
class ReleasePlanTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    public function test_the_funder_total_is_the_moa_grant_when_entered(): void
    {
        // Line items assign less than the grant: the grant still rules, and the existing warning stays.
        AuditLogger::muted(function () {
            $this->project->update(['total_approved_budget' => 2500000, 'funding_agency_amount' => 2000000]);
            $this->lineItem->update(['implementing_agency_amount' => 10000, 'funder_amount' => 490000]);
        });
        $this->actingAs($this->programLeader);
        $url = "/api/program/projects/{$this->project->id}/tranches";

        $page = $this->getJson("/api/program/projects/{$this->project->id}")->assertOk()
            ->assertJsonPath('setup.funder_total', 2000000)->assertJsonPath('setup.funder_total_source', 'moa')
            ->assertJsonPath('setup.funder_grant', 2000000);
        $this->assertSame([2000000.0], array_map('floatval', array_column($page->json('form_b.warnings'), 'typed')));

        $this->postJson($url, ['amount' => 2000000.01, 'planned_release_date' => '2026-02-01'])->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Releases would total ₱2,000,000.01, more than the funder total of ₱2,000,000.00.');
        $this->postJson($url, ['amount' => 2000000, 'planned_release_date' => '2026-02-01'])->assertCreated();
    }

    public function test_without_a_grant_the_funder_total_is_the_funder_line_items(): void
    {
        AuditLogger::muted(function () {
            $this->project->update(['total_approved_budget' => 1210000]);
            $this->lineItem->update(['implementing_agency_amount' => 10000, 'funder_amount' => 490000]);
        });
        $this->actingAs($this->programLeader);
        $url = "/api/program/projects/{$this->project->id}/tranches";

        $this->getJson("/api/program/projects/{$this->project->id}")->assertOk()
            ->assertJsonPath('setup.funder_total', 490000)->assertJsonPath('setup.funder_total_source', 'line_items')
            ->assertJsonPath('setup.funder_grant', null);
        $this->postJson($url, ['amount' => 490000.01, 'planned_release_date' => '2026-02-01'])->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Releases would total ₱490,000.01, more than the funder total of ₱490,000.00.');
        $this->postJson($url, ['amount' => 490000, 'planned_release_date' => '2026-02-01'])->assertCreated();
    }

    public function test_without_a_grant_or_funder_line_items_it_is_the_budget_less_the_counterpart(): void
    {
        AuditLogger::muted(function () {
            $this->project->update(['total_approved_budget' => 1210000]);
            $this->lineItem->update(['implementing_agency_amount' => 10000, 'funder_amount' => 0]);
        });
        $this->actingAs($this->programLeader);
        $url = "/api/program/projects/{$this->project->id}/tranches";

        $this->getJson("/api/program/projects/{$this->project->id}")->assertOk()
            ->assertJsonPath('setup.funder_total', 1200000)->assertJsonPath('setup.funder_total_source', 'budget');
        $this->postJson($url, ['amount' => 1200000.01, 'planned_release_date' => '2026-02-01'])->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'Releases would total ₱1,200,000.01, more than the funder total of ₱1,200,000.00.');
        $this->postJson($url, ['amount' => 1200000, 'planned_release_date' => '2026-02-01'])->assertCreated();
    }

    public function test_the_grant_is_saved_from_the_moa_section_and_cannot_exceed_the_budget(): void
    {
        $this->actingAs($this->programLeader);
        $project = "/api/program/projects/{$this->project->id}";

        $this->patchJson($project, ['funding_agency_amount' => 1000000.01])->assertStatus(422)
            ->assertJsonPath('errors.funding_agency_amount.0', 'The grant amount (₱1,000,000.01) cannot be more than the total budget (₱1,000,000.00).');
        $this->patchJson($project, ['funding_agency_amount' => -1])->assertStatus(422)->assertJsonValidationErrors(['funding_agency_amount']);

        $this->patchJson($project, ['funding_agency_amount' => 800000])->assertOk();
        $this->getJson($project)->assertJsonPath('setup.funder_grant', 800000)->assertJsonPath('setup.funder_total', 800000);
        // Lowering the budget under the grant is refused too.
        $this->patchJson($project, ['total_approved_budget' => 700000])->assertStatus(422)->assertJsonValidationErrors(['funding_agency_amount']);

        // Blank clears it: back to the fixture's funder line items (500,000).
        $this->patchJson($project, ['funding_agency_amount' => null])->assertOk();
        $this->getJson($project)->assertJsonPath('setup.funder_grant', null)
            ->assertJsonPath('setup.funder_total_source', 'line_items')->assertJsonPath('setup.funder_total', 500000);

        // New project with the grant.
        $id = $this->postJson('/api/program/projects', [
            'title' => 'Seaweed Value Chain', 'code' => 'RP-2026-020', 'funding_agency_id' => $this->project->funding_agency_id,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'total_approved_budget' => 2500000,
            'funding_agency_amount' => 2000000, 'project_leader_id' => $this->freeLeader()->id,
        ])->assertCreated()->json('project.id');
        $this->getJson("/api/program/projects/{$id}")->assertJsonPath('setup.funder_total', 2000000);
    }

    public function test_split_70_30_totals_the_funder_amount_exactly(): void
    {
        AuditLogger::muted(fn () => $this->project->update(['total_approved_budget' => 1210000, 'funding_agency_amount' => 1210000]));
        $this->actingAs($this->programLeader);
        $url = "/api/program/projects/{$this->project->id}/tranches/split";

        $this->postJson($url)->assertCreated()
            ->assertJsonPath('tranches.0.name', 'Release 1')->assertJsonPath('tranches.0.amount', 847000)
            ->assertJsonPath('tranches.0.planned_release_date', '2026-01-01')
            ->assertJsonPath('tranches.1.name', 'Release 2')->assertJsonPath('tranches.1.amount', 363000)
            ->assertJsonPath('tranches.1.planned_release_date', '2026-07-02');
        $this->assertSame(['Upon signing of the MOA', 'Upon liquidation of Release 1'],
            ProjectTranche::where('project_id', $this->project->id)->orderBy('release_number')->pluck('name')->all());

        // Only while the project has no releases.
        $this->postJson($url)->assertStatus(422)->assertJsonValidationErrors(['tranche']);
        $this->assertSame(2, ProjectTranche::where('project_id', $this->project->id)->count());

        // Program Leader only.
        $this->actingAs($this->projectLeader)->postJson("/api/program/projects/{$this->otherProject->id}/tranches/split")->assertForbidden();
    }

    public function test_split_names_the_second_milestone_after_the_first_releases_actual_number(): void
    {
        // Legacy data with the first two scheduled releases already removed:
        // the counter is at 2, so the new pair is Release 3 and Release 4.
        AuditLogger::muted(fn () => DB::table('projects')->where('id', $this->project->id)
            ->update(['last_release_number' => 2]));

        $this->actingAs($this->programLeader)
            ->postJson("/api/program/projects/{$this->project->id}/tranches/split")->assertCreated()
            ->assertJsonPath('tranches.0.name', 'Release 3')
            ->assertJsonPath('tranches.1.name', 'Release 4');

        $this->assertSame(
            ['Upon signing of the MOA', 'Upon liquidation of Release 3'],
            ProjectTranche::where('project_id', $this->project->id)->orderBy('release_number')->pluck('name')->all()
        );
    }

    public function test_split_amounts_are_centavos_with_the_last_taking_the_remainder(): void
    {
        foreach ([1210000, 1000000.01, 999999.99, 0.03, 333333.33] as $total) {
            $amounts = ProjectSetupService::splitAmounts($total, [70, 30]);
            $this->assertSame((int) round($total * 100), (int) round(array_sum($amounts) * 100), "split of {$total}");
            foreach ($amounts as $amount) {
                $this->assertSame(round($amount, 2), $amount);
            }
        }
        $this->assertSame([700000.01, 300000.0], ProjectSetupService::splitAmounts(1000000.01, [70, 30]));
    }

    public function test_the_fund_release_warns_while_an_earlier_release_is_not_liquidated(): void
    {
        [$first, $second] = AuditLogger::muted(fn () => [
            ProjectTranche::create(['project_id' => $this->project->id, 'sequence' => 1, 'name' => '', 'amount' => 700000, 'status' => 'upcoming', 'planned_release_date' => '2026-01-15']),
            ProjectTranche::create(['project_id' => $this->project->id, 'sequence' => 2, 'name' => '', 'amount' => 300000, 'status' => 'upcoming', 'planned_release_date' => '2026-07-01']),
        ]);
        $tranches = "/api/projects/{$this->project->id}/tranches";

        // Nothing released yet: no warning.
        $this->actingAs($this->projectLeader)->getJson($tranches)->assertOk()->assertJsonPath('summary.liquidation_warning', null);

        AuditLogger::muted(fn () => $first->update(['status' => 'released', 'released_at' => '2026-01-20']));
        $this->getJson($tranches)->assertJsonPath('summary.liquidation_warning', 'Release 1 is not yet liquidated.');

        // A warning only: the request is still accepted, and the review panel shows the same text.
        $id = $this->postJson("/api/projects/{$this->project->id}/budget-requests", [
            'type' => 'fund_release', 'project_tranche_id' => $second->id,
            'justification' => 'Second release for the field work.',
        ])->assertCreated()->json('budget_request.id');
        $detail = "/api/approvals/{$id}";
        $this->actingAs($this->programLeader)->getJson($detail)->assertOk()
            ->assertJsonPath('approval.details.liquidation_warning', 'Release 1 is not yet liquidated.');

        // A pending liquidation is not enough; an approved one clears the warning.
        $liquidation = AuditLogger::muted(fn () => ApprovalRequest::create([
            'project_id' => $this->project->id, 'requested_by' => $this->projectLeader->id, 'type' => 'liquidation',
            'title' => 'Liquidation', 'amount' => 700000, 'project_tranche_id' => $first->id, 'status' => 'pending',
            'justification' => 'Receipts for Release 1.',
        ]));
        $this->getJson($detail)->assertJsonPath('approval.details.liquidation_warning', 'Release 1 is not yet liquidated.');
        AuditLogger::muted(fn () => $liquidation->update(['status' => 'approved']));
        $this->getJson($detail)->assertJsonPath('approval.details.liquidation_warning', null);
        $this->actingAs($this->projectLeader)->getJson($tranches)->assertJsonPath('summary.liquidation_warning', null);
    }

    public function test_an_approved_row_in_the_older_liquidations_table_also_counts(): void
    {
        $release = AuditLogger::muted(fn () => ProjectTranche::create([
            'project_id' => $this->project->id, 'sequence' => 1, 'name' => '', 'amount' => 500000,
            'status' => 'released', 'released_at' => '2026-01-20', 'planned_release_date' => '2026-01-15',
        ]));
        $tranches = "/api/projects/{$this->project->id}/tranches";
        $this->actingAs($this->projectLeader)->getJson($tranches)->assertJsonPath('summary.liquidation_warning', 'Release 1 is not yet liquidated.');

        AuditLogger::muted(fn () => Liquidation::create([
            'project_id' => $this->project->id, 'project_tranche_id' => $release->id, 'title' => 'Release 1', 'amount' => 500000, 'due_date' => '2026-06-30', 'status' => 'approved',
        ]));
        $this->getJson($tranches)->assertJsonPath('summary.liquidation_warning', null);
    }
}
