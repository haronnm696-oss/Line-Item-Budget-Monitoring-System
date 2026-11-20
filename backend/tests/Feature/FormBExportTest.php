<?php

namespace Tests\Feature;

use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\FormBReportService;
use Database\Seeders\FormBDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsExpenseWorkflow;
use Tests\TestCase;

/**
 * The R&D Form B export (PDF document and CSV) in the official layout, the
 * same for every funder: only the funder's names change.
 */
class FormBExportTest extends TestCase
{
    use RefreshDatabase;
    use BuildsExpenseWorkflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorkflowFixtures();
    }

    private function demo(): Project
    {
        $this->seed(FormBDemoSeeder::class);
        // The workflow fixture created the funder with its short name only.
        FundingAgency::where('acronym', 'MOST-BARMM')->update(['name' => 'Ministry of Science and Technology - BARMM']);

        return Project::where('code', FormBDemoSeeder::CODE)->firstOrFail();
    }

    private function totalRow(array $doc, string $label): array
    {
        $row = collect($doc['rows'])->firstWhere('label', $label);
        $this->assertNotNull($row, "Missing row {$label}");

        return [$row['implementing_agency'], $row['funder']];
    }

    public function test_the_demo_form_b_document_has_the_official_layout_and_totals(): void
    {
        $project = $this->demo();
        $doc = $this->actingAs($this->programLeader)->getJson("/api/projects/{$project->id}/form-b")->assertOk()->json();

        $this->assertSame([
            'form' => 'MOST-BARMM R&D Form B',
            'agency' => 'MINISTRY OF SCIENCE AND TECHNOLOGY - BARMM',
            'title' => 'Project Line-Item Budget',
            'year' => 'CY 2026',
        ], $doc['header']);
        $details = collect($doc['details'])->pluck('value', 'label')->all();
        $this->assertSame(FormBDemoSeeder::TITLE, $details['Project Title']);
        $this->assertSame('MSU', $details['Implementing Agency']);
        $this->assertSame('Twelve (12) months', $details['Total Duration']);
        $this->assertSame('Demo Project Leader', $details['Project Leader']);
        $this->assertSame('Ministry of Science and Technology - BARMM', $details['Monitoring Agency']);
        $this->assertSame(['', 'Counterpart Funding: Implementing Agency', 'MOST-BARMM'], $doc['columns']);

        $this->assertEquals([1058376.00, 351600.00], $this->totalRow($doc, 'Sub-total for PS'));
        $this->assertEquals([120000.00, 1648400.00], $this->totalRow($doc, 'Sub-Total for MOOE'));
        $this->assertEquals([1178376.00, 2000000.00], $this->totalRow($doc, 'GRAND TOTAL'));
        $this->assertSame('(To be filled-up by MOST-BARMM)', $doc['footer']['title']);
        $this->assertSame(['Implementing', 'MOST-BARMM', 'TOTAL'], $doc['footer']['columns']);
        $this->assertEquals([1178376.00, 2000000.00, 3178376.00], $doc['footer']['values']);
        $this->assertSame('* Chargeable against the CY 2026', $doc['footer']['note']);

        // Body order and structure.
        $labels = collect($doc['rows'])->pluck('label')->all();
        $this->assertSame('I. PERSONNEL SERVICES (PS)', $labels[0]);
        $this->assertSame(['Direct Cost', 'Honoraria'], array_slice($labels, 1, 2));
        $this->assertContains('Indirect Cost (Implementing Agency)', $labels);
        $this->assertContains('II. MAINTENANCE AND OTHER OPERATING EXPENSES (MOOE)', $labels);
        $this->assertSame('GRAND TOTAL', end($labels));
        $leader = collect($doc['rows'])->firstWhere('label', 'One (1) Project Leader @ P8,800.00/mo. x 12 mos.');
        $this->assertEquals(['item', 3, 254754.0, 105600.0], [$leader['kind'], $leader['level'], $leader['implementing_agency'], $leader['funder']]);
        $this->assertSame('group', collect($doc['rows'])->firstWhere('label', 'Honoraria')['kind']);
        $this->assertSame(4, collect($doc['rows'])->firstWhere('label', 'One (1) unit @ P33,130.00 x 5')['level']);
        $this->assertContains($doc['paper'], ['A4', 'Legal']);

        // Signatories: labels always, names only when set.
        $this->assertSame(['Reviewed by:', 'Certified Funds Available:', 'Recommending Approval:', 'Approved:'], collect($doc['signatories'])->pluck('label')->all());
        $this->assertSame([null], collect($doc['signatories'])->pluck('name')->unique()->values()->all());
    }

    public function test_the_csv_has_the_same_rows_with_plain_numbers_including_zero(): void
    {
        $project = $this->demo();
        $csv = $this->actingAs($this->programLeader)->get("/api/projects/{$project->id}/reports/form-b")->assertOk()->getContent();
        $lines = explode("\r\n", substr($csv, 3));

        $this->assertSame('MOST-BARMM R&D Form B', $lines[0]);
        $this->assertSame('MINISTRY OF SCIENCE AND TECHNOLOGY - BARMM', $lines[1]);
        $this->assertContains('Total Duration:,Twelve (12) months', $lines);
        $this->assertContains(',Counterpart Funding: Implementing Agency,MOST-BARMM', $lines);
        $this->assertContains('Sub-total for PS,1058376.00,351600.00', $lines);
        $this->assertContains('Sub-Total for MOOE,120000.00,1648400.00', $lines);
        $this->assertContains('GRAND TOTAL,1178376.00,2000000.00', $lines);
        $this->assertContains('(To be filled-up by MOST-BARMM)', $lines);
        $this->assertContains('Implementing,MOST-BARMM,TOTAL', $lines);
        // Pre-filled box with the note beside it.
        $this->assertContains('1178376.00,2000000.00,3178376.00,,* Chargeable against the CY 2026', $lines);
        // Plain numbers only: zero is 0.00 (the PDF prints "-"), so formulas work.
        $this->assertContains('"      Two (2) Project Support Staff Level 2 @ P1,500.00/qtr. x 4 qtrs.",0.00,12000.00', $lines);
        $this->assertContains('      Electricity and water,120000.00,0.00', $lines);
        $this->assertDoesNotMatchRegularExpression('/,-(,|\r|$)/m', $csv);
        $this->assertStringNotContainsString('₱', $csv);
    }

    public function test_signatories_and_monitoring_agency_print_only_when_set(): void
    {
        $project = $this->demo();
        $this->actingAs($this->programLeader);

        $this->patchJson("/api/program/projects/{$project->id}", [
            'monitoring_agency' => 'Regional Monitoring Office',
            'reviewed_by_name' => 'Reviewer Placeholder',
            'reviewed_by_position' => 'Budget Officer',
            'approved_by_name' => '   ',
        ])->assertOk();
        $project->refresh();
        $this->assertNull($project->approved_by_name);
        $this->assertSame('Reviewer Placeholder', $this->getJson("/api/program/projects/{$project->id}")->json('setup.form_b.reviewed_by_name'));

        $doc = app(FormBReportService::class)->build($project);
        $this->assertSame('Regional Monitoring Office', collect($doc['details'])->firstWhere('label', 'Monitoring Agency')['value']);
        $signatories = collect($doc['signatories'])->keyBy('label');
        $this->assertSame(['Reviewer Placeholder', 'Budget Officer'], [$signatories['Reviewed by:']['name'], $signatories['Reviewed by:']['position']]);
        $this->assertNull($signatories['Approved:']['name']);
        $this->assertNull($signatories['Certified Funds Available:']['position']);

        $csv = $this->get("/api/projects/{$project->id}/reports/form-b")->getContent();
        $this->assertStringContainsString("Reviewed by:,Reviewer Placeholder,Budget Officer\r\n", $csv);
        $this->assertStringContainsString("Approved:", $csv);
        $this->assertStringContainsString("Monitoring Agency:,Regional Monitoring Office", $csv);

        // Only the Program Leader edits them; long values are rejected.
        $this->patchJson("/api/program/projects/{$project->id}", ['reviewed_by_name' => str_repeat('x', 256)])
            ->assertStatus(422)->assertJsonValidationErrors(['reviewed_by_name']);
        $this->actingAs($this->projectLeader)->patchJson("/api/program/projects/{$project->id}", ['reviewed_by_name' => 'X'])->assertForbidden();
    }

    public function test_a_dost_project_uses_dost_everywhere_and_never_most(): void
    {
        $dost = FundingAgency::create(['name' => 'Department of Science and Technology', 'acronym' => 'DOST']);
        $project = AuditLogger::muted(function () use ($dost) {
            $project = Project::create([
                'code' => 'DOST-2026-001', 'title' => 'Coastal Water Quality Sensors', 'funding_agency_id' => $dost->id,
                'total_approved_budget' => 600000, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30', 'status' => 'ongoing',
            ]);
            LibCategory::create(FormBDemoSeeder::attributes(['PS', 'Direct', 'Honoraria', null, 'Project Leader', [1, 'Project Leader', 8800, 'month', 6], 0, null]) + ['project_id' => $project->id]);
            LibCategory::create(['project_id' => $project->id, 'category_type' => 'MOOE', 'expense_group' => 'Utilities', 'line_item_name' => 'Utilities', 'implementing_agency_amount' => 24000, 'funder_amount' => 0]);
            $this->assignTo($this->projectLeader, $project, 'project-leader');

            return $project;
        });

        $response = $this->actingAs($this->programLeader)->getJson("/api/projects/{$project->id}/form-b")->assertOk();
        $doc = $response->json();
        $this->assertSame('DOST R&D Form B', $doc['header']['form']);
        $this->assertSame('DEPARTMENT OF SCIENCE AND TECHNOLOGY', $doc['header']['agency']);
        $this->assertSame('DOST', $doc['columns'][2]);
        $this->assertSame('(To be filled-up by DOST)', $doc['footer']['title']);
        $this->assertSame(['Implementing', 'DOST', 'TOTAL'], $doc['footer']['columns']);
        $this->assertSame('* Chargeable against the CY 2026', $doc['footer']['note']);
        $this->assertSame('Six (6) months', collect($doc['details'])->firstWhere('label', 'Total Duration')['value']);
        $this->assertSame('Department of Science and Technology', collect($doc['details'])->firstWhere('label', 'Monitoring Agency')['value']);
        // A lone item named like its group prints on the group line.
        $utilities = collect($doc['rows'])->where('label', 'Utilities')->values();
        $this->assertCount(1, $utilities);
        $this->assertEquals([24000.0, 0.0], [$utilities[0]['implementing_agency'], $utilities[0]['funder']]);
        $this->assertStringNotContainsString('MOST', $response->getContent());

        $csv = $this->get("/api/projects/{$project->id}/reports/form-b")->assertOk()->getContent();
        $this->assertStringContainsString('DOST R&D Form B', $csv);
        $this->assertStringContainsString(',Counterpart Funding: Implementing Agency,DOST', $csv);
        $this->assertStringContainsString('(To be filled-up by DOST)', $csv);
        $this->assertStringNotContainsString('MOST', $csv);
    }

    public function test_export_is_refused_without_a_funder(): void
    {
        $project = $this->demo();
        $project->update(['funding_agency_id' => null]);
        $this->actingAs($this->programLeader);

        $message = "Set the project's funder in Edit project details first.";
        $this->getJson("/api/projects/{$project->id}/form-b")->assertStatus(422)->assertJsonPath('message', $message);
        $this->getJson("/api/projects/{$project->id}/reports/form-b")->assertStatus(422)->assertJsonPath('message', $message);
        // The screens get the same reason to disable the button.
        $this->assertSame($message, $this->getJson("/api/projects/{$project->id}")->json('project.form_b_blocker'));
        $this->assertSame($message, $this->getJson("/api/program/projects/{$project->id}")->json('project.form_b_blocker'));
    }

    public function test_export_is_refused_without_line_items(): void
    {
        $project = AuditLogger::muted(fn () => Project::create([
            'code' => 'RP-2025-031', 'title' => 'No line items yet', 'funding_agency_id' => FundingAgency::first()->id,
            'total_approved_budget' => 100000, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'ongoing',
        ]));
        $this->actingAs($this->programLeader);

        $message = 'Add line items to this project first.';
        $this->getJson("/api/projects/{$project->id}/form-b")->assertStatus(422)->assertJsonPath('message', $message);
        $this->getJson("/api/projects/{$project->id}/reports/form-b")->assertStatus(422)->assertJsonPath('message', $message);
        $this->assertSame($message, $this->getJson("/api/projects/{$project->id}")->json('project.form_b_blocker'));
        $this->assertNull($this->getJson("/api/projects/{$this->demo()->id}")->json('project.form_b_blocker'));
    }

    public function test_the_implementing_agency_override_is_printed(): void
    {
        $project = $this->demo();
        $this->actingAs($this->programLeader);

        $this->patchJson("/api/program/projects/{$project->id}", ['implementing_agency_name' => ' MSU-Marawi '])->assertOk();
        $this->assertSame('MSU-Marawi', $this->getJson("/api/program/projects/{$project->id}")->json('setup.form_b.implementing_agency_name'));
        $doc = $this->getJson("/api/projects/{$project->id}/form-b")->assertOk()->json();
        $this->assertSame('MSU-Marawi', collect($doc['details'])->firstWhere('label', 'Implementing Agency')['value']);
        $this->assertSame(FormBDemoSeeder::CODE, $doc['project_code']);
        $this->assertStringContainsString('Implementing Agency:,MSU-Marawi', $this->get("/api/projects/{$project->id}/reports/form-b")->getContent());

        // Blank falls back to the IMPLEMENTING_AGENCY setting.
        $this->patchJson("/api/program/projects/{$project->id}", ['implementing_agency_name' => ''])->assertOk();
        $doc = $this->getJson("/api/projects/{$project->id}/form-b")->json();
        $this->assertSame('MSU', collect($doc['details'])->firstWhere('label', 'Implementing Agency')['value']);
    }

    public function test_duration_words(): void
    {
        $service = app(FormBReportService::class);
        $d = fn ($a, $b) => $service->duration(\Carbon\Carbon::parse($a), \Carbon\Carbon::parse($b));
        $this->assertSame('Twelve (12) months', $d('2026-07-01', '2027-06-30'));
        $this->assertSame('One (1) month', $d('2026-01-01', '2026-01-31'));
        $this->assertSame('Eighteen (18) months', $d('2026-01-01', '2027-06-15'));
        $this->assertSame('', $service->duration(null, null));
    }
}
