<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectPersonnel;
use App\Models\ProjectTranche;
use App\Models\PurchaseRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * Each set-up step with exactly the fields its form sends, nothing more:
 * New project, line item, release, personnel, expense and purchase request.
 * Production MySQL runs in strict mode, so an insert that leaves a NOT NULL
 * column without a default unset fails there (SQLSTATE 1364) while SQLite
 * may not notice. Run it on MySQL too, against a scratch database (never
 * the real one: RefreshDatabase wipes it). Setting DB_CONNECTION=mysql on
 * the command line does NOT work: the <server> entries in phpunit.xml
 * override it and the test quietly runs on SQLite. Instead:
 *   1. Create an empty scratch database (utf8mb4) on a MySQL in strict mode.
 *   2. Copy backend/phpunit.xml to backend/phpunit.mysql.xml (same folder, so
 *      its relative paths still work; it holds a password, so it is
 *      git-ignored in backend/.gitignore)
 *      and replace the DB_CONNECTION / DB_DATABASE <server> entries with
 *      DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD and
 *      DB_DATABASE=<scratch db>.
 *   3. From backend/: php vendor/bin/phpunit -c phpunit.mysql.xml --filter=FormInsertsTest
 *   4. Drop the scratch database afterwards.
 * To check that MySQL was really used, the scratch database has the tables after the run.
 */
class FormInsertsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    public function test_every_form_saves_with_only_the_fields_it_sends(): void
    {
        $this->actingAs($this->programLeader);

        // New project (ProjectFormDialog): MOA fields and the grant left blank.
        $projectId = $this->postJson('/api/program/projects', [
            'title' => 'Seaweed Value Chain', 'code' => 'RP-2026-030',
            'funding_agency_id' => $this->project->funding_agency_id, 'funder_reference' => null,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'total_approved_budget' => '3,178,376.00', 'project_leader_id' => $this->freeLeader()->id,
            'moa_number' => null, 'moa_date' => null, 'rd_committee_approved_date' => null, 'fund_source' => null,
            'funding_agency_amount' => null,
        ])->assertCreated()->json('project.id');
        $project = Project::findOrFail($projectId);
        $this->assertSame('0.00', (string) $project->implementing_agency_counterpart);
        $this->assertSame('0.00', (string) $project->funding_agency_amount);
        $this->assertSame('3178376.00', (string) $project->total_approved_budget);

        // Line items (LineItemDialog): an MOOE line, and a PS line for the honorarium.
        $lineItem = fn (array $fields) => $this->postJson("/api/program/projects/{$projectId}/line-items", array_merge([
            'cost_type' => 'Direct', 'expense_subgroup' => null, 'quantity' => null, 'unit_description' => null,
            'rate' => null, 'rate_period' => null, 'periods' => null, 'implementing_agency_amount' => 0,
        ], $fields))->assertCreated()->json('line_item.id');
        $mooe = $lineItem(['category_type' => 'MOOE', 'expense_group' => 'Supplies and Materials Expenses', 'line_item_name' => 'Laboratory supplies', 'funder_amount' => '500,000.00']);
        $ps = $lineItem(['category_type' => 'PS', 'expense_group' => 'Honoraria', 'line_item_name' => 'Project Staff', 'funder_amount' => 105600]);
        $this->assertSame('500000.00', (string) LibCategory::findOrFail($mooe)->allocated_amount);

        // Release (TrancheDialog).
        $this->postJson("/api/program/projects/{$projectId}/tranches", ['name' => null, 'amount' => '400,000.00', 'planned_release_date' => '2026-02-01'])
            ->assertCreated();
        $this->assertSame(1, ProjectTranche::where('project_id', $projectId)->count());

        // The Research Assistant joins the project, then gets a personnel record (PersonnelSection).
        $this->postJson('/api/program/assignments', ['user_id' => $this->assistant->id, 'project_id' => $projectId, 'role' => 'research-assistant'])
            ->assertSuccessful();
        $this->postJson("/api/program/projects/{$projectId}/personnel", [
            'user_id' => $this->assistant->id, 'position' => 'Project Staff Level 3', 'lib_category_id' => $ps,
            'special_order_no' => 'SO No. 1111-OP s. 2026', 'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31',
            'monthly_honorarium' => '8,800.00',
        ])->assertCreated();
        $this->assertSame(1, ProjectPersonnel::where('project_id', $projectId)->count());

        // Expense (RecordExpenseDialog) and purchase request (PurchaseRequestDialog), as the assistant.
        $this->actingAs($this->assistant);
        $this->postJson("/api/projects/{$projectId}/expenses", [
            'lib_category_id' => $mooe, 'funding_source' => 'funder', 'description' => 'Laboratory reagents',
            'amount' => '2,500.50', 'expense_date' => '2026-03-10', 'payee' => null, 'reference_no' => null, 'purchase_request_id' => null,
        ])->assertCreated();
        $this->postJson("/api/projects/{$projectId}/purchase-requests", [
            'purpose' => 'Glassware', 'supplier' => null, 'funding_source' => 'funder',
            'items' => [['lib_category_id' => $mooe, 'quantity' => 2, 'unit' => 'set', 'description' => 'Beakers', 'unit_cost' => '1,750.00']],
        ])->assertCreated();

        $this->assertSame('2500.50', (string) Expense::where('project_id', $projectId)->value('amount'));
        $this->assertSame('3500.00', (string) PurchaseRequest::where('project_id', $projectId)->value('estimated_amount'));
        $this->assertSame('3500.00', (string) \App\Models\PurchaseRequestItem::query()->value('estimated_cost'));
    }
}
