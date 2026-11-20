<?php

namespace Tests\Feature;

use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ProjectFinancialService;
use App\Support\LineItemComputation;
use Database\Seeders\FormBDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * The Line-Item Budget as R&D Form B: cost type, expense groups and
 * sub-groups, the optional computation, counterpart and funder amounts,
 * spending per funding source, realignment within a source, and funder
 * labels taken from the project's funder (never a built-in one).
 */
class FormBLineItemBudgetTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    // ---------------------------------------------------------------- the Form B fixture

    public function test_the_form_b_demo_project_has_the_official_totals(): void
    {
        $this->seed(FormBDemoSeeder::class);
        $project = Project::where('code', FormBDemoSeeder::CODE)->firstOrFail();
        $this->assertSame(FormBDemoSeeder::TITLE, $project->title);
        $this->assertSame('MOST-BARMM', $project->fundingAgency->acronym);
        $this->assertSame(12, (int) round($project->start_date->floatDiffInMonths($project->end_date->copy()->addDay())));

        $formB = app(ProjectFinancialService::class)->formB($project);
        $sections = collect($formB['sections'])->keyBy('type');

        $this->assertSame(['implementing_agency' => 1058376.0, 'funder' => 351600.0, 'total' => 1409976.0], $sections['PS']['subtotal']);
        $this->assertSame(['implementing_agency' => 120000.0, 'funder' => 1648400.0, 'total' => 1768400.0], $sections['MOOE']['subtotal']);
        $this->assertSame(['implementing_agency' => 1178376.0, 'funder' => 2000000.0, 'total' => 3178376.0], $formB['grand_total']);

        // Project totals are the line-item sums.
        $this->assertSame(['implementing_agency' => 1178376.0, 'funder' => 2000000.0, 'total' => 3178376.0],
            app(ProjectFinancialService::class)->fundingTotals($project));
        $this->assertSame(3178376.0, (float) $project->libCategories()->sum('allocated_amount'));

        // PS and MOOE each split into Direct and Indirect with group headings.
        $this->assertSame(['Direct', 'Indirect'], collect($sections['PS']['cost_types'])->pluck('cost_type')->all());
        $this->assertSame(['Direct', 'Indirect'], collect($sections['MOOE']['cost_types'])->pluck('cost_type')->all());
        $supplies = collect($sections['MOOE']['cost_types'][0]['groups'])->firstWhere('name', 'Supplies and Materials Expenses');
        $this->assertSame('Semi-Expendable Machinery and Equipment', $supplies['subgroups'][0]['name']);
        $this->assertSame(['IoT tracking devices', 'Laptop computers', 'Tablets'], collect($supplies['subgroups'][0]['items'])->pluck('name')->all());
        $this->assertSame(['implementing_agency' => 0.0, 'funder' => 496850.0, 'total' => 496850.0], $supplies['subgroups'][0]['subtotal']);

        // No real people's names in the seed: the leader is a placeholder.
        $this->assertSame(['Demo Project Leader'], $project->usersWithRole('project-leader')->pluck('name')->all());
    }

    public function test_the_computed_rows_of_the_form_b_fixture(): void
    {
        $this->seed(FormBDemoSeeder::class);
        $items = Project::where('code', FormBDemoSeeder::CODE)->firstOrFail()->libCategories()->get()->keyBy('line_item_name');

        // The official PS rows: the computation is the funder column, the
        // counterpart is typed on its own and added.
        // name => [quantity, rate, period, periods, funder (computed), counterpart, text]
        $expected = [
            'Project Leader' => [1, 8800, 'month', 12, 105600.0, 254754.0, 'One (1) Project Leader @ ₱8,800.00/mo. x 12 mos.'],
            'Project Staff Level 3' => [1, 7500, 'month', 12, 90000.0, 509508.0, 'One (1) Project Staff Level 3 @ ₱7,500.00/mo. x 12 mos.'],
            'Project Staff Level 2' => [2, 6000, 'month', 12, 144000.0, 294114.0, 'Two (2) Project Staff Level 2 @ ₱6,000.00/mo. x 12 mos.'],
            'Project Support Staff Level 2' => [2, 1500, 'quarter', 4, 12000.0, 0.0, 'Two (2) Project Support Staff Level 2 @ ₱1,500.00/qtr. x 4 qtrs.'],
            'IoT tracking devices' => [1, 56070, null, 5, 280350.0, 0.0, 'One (1) lot @ ₱56,070.00 x 5'],
            'Laptop computers' => [1, 33130, null, 5, 165650.0, 0.0, 'One (1) unit @ ₱33,130.00 x 5'],
            'Tablets' => [1, 16950, null, 3, 50850.0, 0.0, 'One (1) unit @ ₱16,950.00 x 3'],
        ];
        foreach ($expected as $name => [$quantity, $rate, $period, $periods, $funder, $counterpart, $text]) {
            $item = $items[$name];
            $this->assertSame([$quantity, (float) $rate, $period, $periods], [$item->quantity, (float) $item->rate, $item->rate_period, $item->periods], $name);
            $this->assertSame($funder, $item->computedAmount(), $name);
            $this->assertSame($funder, (float) $item->funder_amount, $name);
            $this->assertSame($counterpart, (float) $item->implementing_agency_amount, $name);
            $this->assertSame(round($funder + $counterpart, 2), (float) $item->allocated_amount, $name);
            $this->assertSame($text, $item->computationText(), $name);
        }
        $this->assertSame('Indirect', $items['Project Support Staff Level 2']->cost_type);

        // PS totals come from exactly these four rows.
        $ps = $items->where('category_type', 'PS');
        $this->assertSame(['Project Leader', 'Project Staff Level 3', 'Project Staff Level 2', 'Project Support Staff Level 2'], $ps->keys()->values()->all());
        $this->assertSame([1058376.0, 351600.0], [round((float) $ps->sum('implementing_agency_amount'), 2), round((float) $ps->sum('funder_amount'), 2)]);
    }

    public function test_computation_wording(): void
    {
        $this->assertSame(105600.0, LineItemComputation::amount(['quantity' => 1, 'rate' => 8800, 'periods' => 12]));
        $this->assertSame(500.0, LineItemComputation::amount(['quantity' => 5, 'rate' => 100]));
        $this->assertNull(LineItemComputation::amount(['quantity' => 5]));
        $this->assertSame('One (1) Project Leader @ ₱8,800.00/mo. x 1 mo.',
            LineItemComputation::describe(['quantity' => 1, 'unit_description' => 'Project Leader', 'rate' => 8800, 'rate_period' => 'month', 'periods' => 1]));
        $this->assertSame('Twenty-one (21) units @ ₱10.00', LineItemComputation::describe(['quantity' => 21, 'unit_description' => 'units', 'rate' => 10]));
        $this->assertSame('one hundred five thousand six hundred', LineItemComputation::words(105600));
    }

    // ---------------------------------------------------------------- set-up API

    public function test_the_computation_sets_the_funder_amount_and_the_counterpart_is_added(): void
    {
        $url = "/api/program/projects/{$this->project->id}/line-items";
        $item = $this->actingAs($this->programLeader)->postJson($url, [
            'category_type' => 'PS', 'cost_type' => 'Direct', 'expense_group' => 'Honoraria',
            'line_item_name' => 'Project Leader', 'quantity' => 1, 'unit_description' => 'Project Leader',
            'rate' => 8800, 'rate_period' => 'month', 'periods' => 12, 'implementing_agency_amount' => 254754,
            'funder_amount' => 1, // read-only with a computation: ignored
        ])->assertCreated()
            ->assertJsonPath('line_item.computation', 'One (1) Project Leader @ ₱8,800.00/mo. x 12 mos.')
            ->assertJsonPath('line_item.implementing_agency_amount', 254754)
            ->assertJsonPath('line_item.funder_amount', 105600)
            ->assertJsonPath('line_item.allocated', 360354)
            ->json('line_item');
        // The same name may repeat in another cost type, not in the same place.
        $this->postJson($url, ['category_type' => 'PS', 'cost_type' => 'Indirect', 'expense_group' => 'Honoraria', 'line_item_name' => 'Project Leader', 'funder_amount' => 1000])->assertCreated();
        $this->postJson($url, ['category_type' => 'PS', 'cost_type' => 'Direct', 'expense_group' => 'Honoraria', 'line_item_name' => 'project leader', 'funder_amount' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors(['line_item_name']);
        // Without a computation, both amounts are typed.
        $this->postJson($url, ['category_type' => 'MOOE', 'expense_group' => 'Utilities', 'line_item_name' => 'Electricity', 'implementing_agency_amount' => 2000, 'funder_amount' => 3000])
            ->assertCreated()->assertJsonPath('line_item.allocated', 5000)->assertJsonPath('line_item.cost_type', 'Direct');

        // Editing the rate recomputes the funder amount; the counterpart stays.
        $this->patchJson("/api/program/line-items/{$item['id']}", ['rate' => 9000])->assertOk()
            ->assertJsonPath('line_item.funder_amount', 108000)->assertJsonPath('line_item.implementing_agency_amount', 254754)
            ->assertJsonPath('line_item.allocated', 362754);
        // Editing the counterpart leaves the computed funder amount alone.
        $this->patchJson("/api/program/line-items/{$item['id']}", ['implementing_agency_amount' => 5600])->assertOk()
            ->assertJsonPath('line_item.funder_amount', 108000)->assertJsonPath('line_item.allocated', 113600);
        // Clearing the computation makes both amounts typed again.
        $this->patchJson("/api/program/line-items/{$item['id']}", ['quantity' => null, 'rate' => null, 'rate_period' => null, 'periods' => null, 'funder_amount' => 100000])->assertOk()
            ->assertJsonPath('line_item.computation', null)->assertJsonPath('line_item.funder_amount', 100000)->assertJsonPath('line_item.allocated', 105600);

        // Supplies 500,000 + Project Leader 5,600/100,000 + Indirect 1,000 + Electricity 2,000/3,000.
        $setup = $this->getJson("/api/program/projects/{$this->project->id}")->assertOk()->json('setup');
        $this->assertSame(['implementing_agency' => 7600, 'funder' => 604000, 'total' => 611600], $setup['funding_totals']);
    }

    public function test_computed_amounts_follow_the_lock_rules(): void
    {
        $item = AuditLogger::muted(fn () => LibCategory::create(FormBDemoSeeder::attributes(
            ['PS', 'Direct', 'Honoraria', null, 'Project Leader', [1, 'Project Leader', 8800, 'month', 12], 1000, null]
        ) + ['project_id' => $this->project->id]));
        $this->expenseIn('verified', null, true, ['amount' => 100]);

        $this->actingAs($this->programLeader);
        $this->patchJson("/api/program/line-items/{$item->id}", ['rate' => 9000])
            ->assertStatus(422)->assertJsonPath('errors.funder_amount.0', \App\Services\ProjectSetupService::REALIGNMENT_ONLY);
        $this->patchJson("/api/program/line-items/{$item->id}", ['implementing_agency_amount' => 2000])
            ->assertStatus(422)->assertJsonPath('errors.implementing_agency_amount.0', \App\Services\ProjectSetupService::REALIGNMENT_ONLY);
        $this->patchJson("/api/program/line-items/{$item->id}", ['unit_description' => 'Project Leader (part-time)'])->assertOk();
        $this->assertSame([105600.0, 1000.0], [(float) $item->fresh()->funder_amount, (float) $item->fresh()->implementing_agency_amount]);
    }

    public function test_a_typed_project_amount_not_assigned_to_line_items_is_flagged(): void
    {
        AuditLogger::muted(fn () => $this->project->update(['implementing_agency_counterpart' => 1178376, 'funding_agency_amount' => 500000, 'total_approved_budget' => 3178376]));
        // Line items: Supplies 500,000 funder, no counterpart.
        $this->actingAs($this->programLeader);
        $warnings = $this->getJson("/api/program/projects/{$this->project->id}")->assertOk()->json('form_b.warnings');
        $this->assertSame(['Counterpart typed for this project (₱1,178,376.00) is not yet assigned to line items (₱0.00 assigned).'], array_column($warnings, 'message'));

        // The Form B export preview (report summary) carries the same warning.
        $summary = app(\App\Services\DashboardService::class)->build($this->project->fresh());
        $this->assertSame(array_column($warnings, 'message'), array_column($summary['form_b']['warnings'], 'message'));

        // Funder too; nothing changes automatically.
        AuditLogger::muted(fn () => $this->project->update(['funding_agency_amount' => 2000000]));
        $messages = array_column($this->getJson("/api/program/projects/{$this->project->id}")->json('form_b.warnings'), 'message');
        $this->assertContains('Funder amount (MOST-BARMM) typed for this project (₱2,000,000.00) is not yet assigned to line items (₱500,000.00 assigned).', $messages);
        $this->assertSame(1178376.0, (float) $this->project->fresh()->implementing_agency_counterpart);

        // Gone once the line items match.
        $this->patchJson("/api/program/line-items/{$this->lineItem->id}", ['implementing_agency_amount' => 1178376, 'funder_amount' => 2000000])->assertOk();
        $this->assertSame([], $this->getJson("/api/program/projects/{$this->project->id}")->json('form_b.warnings'));
    }

    public function test_amount_locks_still_apply_per_source_after_expenses(): void
    {
        $item = $this->counterpartItem(10000, 40000);
        $this->expenseIn('submitted', null, true, ['lib_category_id' => $item->id, 'funding_source' => 'implementing_agency', 'amount' => 6000]);

        $this->actingAs($this->programLeader)->patchJson("/api/program/line-items/{$item->id}", ['implementing_agency_amount' => 5999.99])
            ->assertStatus(422)->assertJsonValidationErrors(['implementing_agency_amount']);
        $this->patchJson("/api/program/line-items/{$item->id}", ['implementing_agency_amount' => 6000])->assertOk();
        $this->deleteJson("/api/program/line-items/{$item->id}")->assertStatus(422);

        $this->expenseIn('verified', null, true, ['lib_category_id' => $item->id, 'amount' => 100]);
        $this->patchJson("/api/program/line-items/{$item->id}", ['funder_amount' => 41000])
            ->assertStatus(422)->assertJsonPath('errors.funder_amount.0', \App\Services\ProjectSetupService::REALIGNMENT_ONLY);
        // Names and groups can still change.
        $this->patchJson("/api/program/line-items/{$item->id}", ['expense_group' => 'Supplies and Materials Expenses', 'expense_subgroup' => 'Semi-Expendable'])->assertOk();
    }

    // ---------------------------------------------------------------- spending per source

    public function test_an_expense_draws_from_the_funder_unless_the_counterpart_is_chosen(): void
    {
        $this->actingAs($this->assistant);
        // Supplies has no counterpart: the funder only.
        $id = $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload())->assertCreated()->json('expense.id');
        $this->assertSame('funder', \App\Models\Expense::find($id)->funding_source);
        $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload(['funding_source' => 'implementing_agency']))
            ->assertStatus(422)->assertJsonValidationErrors(['funding_source']);

        $item = $this->counterpartItem(1000, 5000);
        $counterpart = $this->postJson("/api/projects/{$this->project->id}/expenses", $this->expensePayload([
            'lib_category_id' => $item->id, 'funding_source' => 'implementing_agency', 'amount' => 1500,
        ]))->assertCreated()->assertJsonPath('expense.funding_source', 'implementing_agency')->json('expense.id');
        $this->receiptFor(\App\Models\Expense::find($counterpart), $this->assistant);

        // The balance check uses the chosen source: 1,500 > 1,000 counterpart.
        $this->postJson("/api/expenses/{$counterpart}/submit")->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'The amount (₱1,500.00) is more than the ₱1,000.00 available on Travel (MSU counterpart). Lower the amount or ask the Project Leader for a realignment.');
        // Moved to the funder share it fits.
        $this->patchJson("/api/expenses/{$counterpart}", ['funding_source' => 'funder'])->assertOk();
        $this->postJson("/api/expenses/{$counterpart}/submit")->assertOk();

        $row = app(ProjectFinancialService::class)->balancing($this->project, $item->id)['line_items'][0];
        $this->assertSame(['allocated' => 5000.0, 'pending' => 1500.0, 'available' => 3500.0],
            array_intersect_key($row['sources']['funder'], array_flip(['allocated', 'pending', 'available'])));
        $this->assertSame(1000.0, $row['sources']['implementing_agency']['available']);
        $this->assertSame(4500.0, $row['available']);
        $this->assertTrue($row['has_counterpart']);
    }

    public function test_realignment_moves_money_within_one_source(): void
    {
        $from = $this->counterpartItem(20000, 10000);
        $to = AuditLogger::muted(fn () => LibCategory::create(['project_id' => $this->project->id, 'category_type' => 'MOOE', 'line_item_name' => 'Equipment', 'funder_amount' => 1000]));
        $send = fn (array $extra) => $this->actingAs($this->projectLeader)->postJson("/api/projects/{$this->project->id}/budget-requests", $extra + [
            'type' => 'realignment', 'from_lib_category_id' => $from->id, 'to_lib_category_id' => $to->id,
            'justification' => 'Equipment is needed for field tests.',
        ]);

        $send(['amount' => 10000.01])->assertStatus(422)->assertJsonPath('errors.amount.0', 'The source line item has only ₱10,000.00 available (MOST-BARMM share).');
        $id = $send(['amount' => 15000, 'funding_source' => 'implementing_agency'])->assertCreated()
            ->assertJsonPath('budget_request.payload.funding_source', 'implementing_agency')->json('budget_request.id');

        $this->actingAs($this->programLeader)->postJson("/api/program/approvals/{$id}/decision", ['decision' => 'approved'])->assertOk();
        $from->refresh();
        $to->refresh();
        $this->assertSame([5000.0, 10000.0, 15000.0], [(float) $from->implementing_agency_amount, (float) $from->funder_amount, (float) $from->allocated_amount]);
        $this->assertSame([15000.0, 1000.0, 16000.0], [(float) $to->implementing_agency_amount, (float) $to->funder_amount, (float) $to->allocated_amount]);
    }

    // ---------------------------------------------------------------- existing data

    public function test_the_migration_backfills_existing_line_items_without_losing_anything(): void
    {
        $id = DB::table('lib_categories')->insertGetId([
            'project_id' => $this->project->id, 'category_type' => 'PS', 'line_item_name' => 'Honoraria - RA',
            'line_item_code' => 'PS-01', 'allocated_amount' => 360000, 'expense_group' => null,
            'funder_amount' => 0, 'implementing_agency_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_11_23_000001_add_form_b_fields_to_lib_categories.php'))->backfill();

        $row = DB::table('lib_categories')->find($id);
        $this->assertSame(['Direct', 'Honoraria - RA', 360000.0, 0.0, 360000.0, 'PS-01'],
            [$row->cost_type, $row->expense_group, (float) $row->funder_amount, (float) $row->implementing_agency_amount, (float) $row->allocated_amount, $row->line_item_code]);
        // Existing expenses draw from the funder.
        $this->assertSame('funder', DB::table('expenses')->insertGetId([
            'project_id' => $this->project->id, 'lib_category_id' => $id, 'recorded_by' => $this->assistant->id,
            'description' => 'x', 'amount' => 1, 'expense_date' => '2026-11-01', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]) ? DB::table('expenses')->where('lib_category_id', $id)->value('funding_source') : null);
        // Writers that set only allocated_amount keep changing the funder share.
        AuditLogger::muted(fn () => LibCategory::find($id)->update(['allocated_amount' => 400000]));
        $this->assertSame(400000.0, (float) LibCategory::find($id)->funder_amount);
    }

    // ---------------------------------------------------------------- funders

    public function test_a_dost_project_shows_dost_as_the_funder_column_and_never_most(): void
    {
        $dost = FundingAgency::create(['name' => 'Department of Science and Technology', 'acronym' => 'DOST']);
        $project = AuditLogger::muted(function () use ($dost) {
            $project = Project::create([
                'code' => 'DOST-2026-001', 'title' => 'Coastal Water Quality Sensors', 'funding_agency_id' => $dost->id,
                'total_approved_budget' => 600000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'ongoing',
            ]);
            LibCategory::create(FormBDemoSeeder::attributes(['PS', 'Direct', 'Honoraria', null, 'Project Leader', [1, 'Project Leader', 8800, 'month', 12], 0, null]) + ['project_id' => $project->id]);
            LibCategory::create(['project_id' => $project->id, 'category_type' => 'MOOE', 'expense_group' => 'Utilities', 'line_item_name' => 'Electricity', 'implementing_agency_amount' => 24000, 'funder_amount' => 0]);
            $this->assignTo($this->projectLeader, $project, 'project-leader');

            return $project;
        });

        $detail = $this->actingAs($this->programLeader)->getJson("/api/program/projects/{$project->id}")->assertOk();
        $this->assertSame('DOST', $detail->json('form_b.labels.funder'));
        $this->assertSame('DOST', $detail->json('setup.labels.funder'));
        $this->assertSame(['implementing_agency' => 24000, 'funder' => 105600, 'total' => 129600], $detail->json('form_b.grand_total'));
        $this->assertStringNotContainsString('MOST', $detail->getContent());

        $csv = $this->get("/api/projects/{$project->id}/reports/form-b")->assertOk()->getContent();
        $this->assertStringContainsString(',Counterpart Funding: Implementing Agency,DOST', $csv);
        $this->assertStringNotContainsString('MOST', $csv);

        $overview = $this->actingAs($this->projectLeader)->getJson("/api/projects/{$project->id}/leader-overview")->assertOk();
        $this->assertSame('DOST', $overview->json('project.funder'));
        $this->assertSame([105600, 24000], [$overview->json('kpis.funder_share'), $overview->json('kpis.counterpart')]);
        $this->assertStringNotContainsString('MOST', $overview->getContent());
        $this->assertSame(2, $overview->json('alerts.line_item_count'));
        // The counterpart is named after the implementing agency: the Form B field, else IMPLEMENTING_AGENCY.
        $this->assertSame(config('app.implementing_agency'), $overview->json('project.implementing_agency'));
        $project->update(['implementing_agency_name' => ' MSU-Marawi ']);
        $this->assertSame('MSU-Marawi', $this->getJson("/api/projects/{$project->id}/leader-overview")->json('project.implementing_agency'));
    }

    public function test_the_program_leader_edits_funder_names(): void
    {
        $this->actingAs($this->assistant)->getJson('/api/program/funders')->assertForbidden();
        $this->postJson('/api/program/funders', ['name' => 'X', 'acronym' => 'X'])->assertForbidden();

        $this->actingAs($this->programLeader);
        $id = $this->postJson('/api/program/funders', ['name' => 'Department of Science and Technology', 'acronym' => 'DOST'])
            ->assertCreated()->assertJsonPath('funder.short_name', 'DOST')->json('funder.id');
        $this->postJson('/api/program/funders', ['name' => 'Another', 'acronym' => 'DOST'])->assertStatus(422)->assertJsonValidationErrors(['acronym']);
        $this->postJson('/api/program/funders', ['name' => '', 'acronym' => ''])->assertStatus(422)->assertJsonValidationErrors(['name', 'acronym']);

        $most = $this->project->funding_agency_id;
        $this->patchJson("/api/program/funders/{$most}", ['name' => 'Ministry of Science and Technology - BARMM', 'acronym' => 'MOST'])->assertOk()
            ->assertJsonPath('funder.name', 'Ministry of Science and Technology - BARMM');
        $this->assertSame('MOST', $this->getJson("/api/program/projects/{$this->project->id}")->json('form_b.labels.funder'));
        $this->assertContains($id, collect($this->getJson('/api/program/funders')->json('funders'))->pluck('id')->all());
    }

    // ---------------------------------------------------------------- helpers

    private function counterpartItem(float $counterpart, float $funder): LibCategory
    {
        return AuditLogger::muted(fn () => LibCategory::create([
            'project_id' => $this->project->id, 'category_type' => 'MOOE', 'expense_group' => 'Traveling Expenses',
            'line_item_name' => 'Travel', 'implementing_agency_amount' => $counterpart, 'funder_amount' => $funder,
        ]));
    }
}
