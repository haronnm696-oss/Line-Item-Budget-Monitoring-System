<?php

namespace Database\Seeders;

use App\Models\ApprovalRequest;
use App\Models\AuditTrail;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Liquidation;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\ProjectTranche;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Concerns\AssignsDemoFunding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Single source of demo project data.
 *
 * All dates are Carbon-relative so statuses, tranches, forecasts, and
 * deadlines stay sensible whenever the database is seeded. Every financial
 * figure comes from verified expenses, never from a hand-written total.
 */
class DemoDatasetSeeder extends Seeder
{
    use AssignsDemoFunding;

    public function run(): void
    {
        $today = now()->startOfDay();
        $now = now();

        $users = User::whereIn('email', [
            'program.leader@gmail.com',
            'project.leader@gmail.com',
            'project.leader2@gmail.com',
            'project.leader3@gmail.com',
            'project.leader4@gmail.com',
            'assistant@gmail.com',
            'finance@gmail.com',
            'researcher@gmail.com',
        ])->get()->keyBy('email');

        if ($users->count() < 8) {
            $this->command?->warn('Demo users are missing; run DemoUsersSeeder first.');
            return;
        }

        $roles = \App\Models\Role::whereIn('slug', [
            'program-leader', 'project-leader', 'research-assistant', 'researcher', 'finance-admin',
        ])->get()->keyBy('slug');

        $leading = $users['program.leader@gmail.com'];
        $ra = $users['assistant@gmail.com'];
        $finance = $users['finance@gmail.com'];

        $funders = [
            'MOST-BARMM' => FundingAgency::updateOrCreate(
                ['acronym' => 'MOST-BARMM'],
                ['name' => 'Ministry of Science and Technology – BARMM']
            ),
            'DOST-PCAARRD' => FundingAgency::updateOrCreate(
                ['acronym' => 'DOST-PCAARRD'],
                ['name' => 'DOST – Philippine Council for Agriculture, Aquatic and Natural Resources Research and Development']
            ),
            'CHED' => FundingAgency::updateOrCreate(
                ['acronym' => 'CHED'],
                ['name' => 'Commission on Higher Education']
            ),
            'DOST-PCHRD' => FundingAgency::updateOrCreate(
                ['acronym' => 'DOST-PCHRD'],
                ['name' => 'DOST – Philippine Council for Health Research and Development']
            ),
        ];

        $definitions = $this->projectDefinitions($today);
        $codes = array_column($definitions, 'code');
        $this->resetSeedAuditRows($codes);

        foreach ($definitions as $definition) {
            $funder = $funders[$definition['funder']];
            $status = $this->derivedStatus($definition['start'], $definition['end']);
            $counterpart = $definition['counterpart'];
            $fundingAmount = $definition['budget'] - $counterpart;

            $project = Project::updateOrCreate(
                ['code' => $definition['code']],
                [
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'funding_agency_id' => $funder->id,
                    'total_approved_budget' => $definition['budget'],
                    'implementing_agency_counterpart' => $counterpart,
                    'funding_agency_amount' => $fundingAmount,
                    'start_date' => $definition['start']->toDateString(),
                    'end_date' => $definition['end']->toDateString(),
                    'status' => $status,
                ]
            );

            $this->assignTeam($project, $roles, [
                $leading,
                $users[$definition['leader_email']],
                $ra,
                $finance,
                $users['researcher@gmail.com'],
            ]);
            $this->assignDemoFunding($project, $definition['key']);

            $categories = $this->seedLineItems($project, $definition['line_items']);
            $this->seedTranches($project, $definition, $today);
            $this->seedExpenses($project, $categories, $definition, $ra, $finance);

            $tranches = ProjectTranche::where('project_id', $project->id)->orderBy('sequence')->get()->keyBy('sequence');
            $categories = LibCategory::where('project_id', $project->id)->get()->keyBy('line_item_code');
            $liquidations = $this->seedLiquidations(
                $project,
                $definition,
                $tranches,
                $users[$definition['leader_email']],
                $leading,
                $today
            );
            $this->seedApprovals(
                $project,
                $definition,
                $tranches,
                $categories,
                $liquidations,
                $users[$definition['leader_email']],
                $leading,
                $today
            );
            $this->seedReports($project, $definition, $today);

            $this->seedAuditTrail($project, $leading, $ra, $finance);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectDefinitions(Carbon $today): array
    {
        return [
            [
                'key' => 'urban',
                'code' => 'MOST-RD-2026-001',
                'title' => 'Enhancing Urban Mobility in Marawi City',
                'description' => 'MOST-BARMM R&D project using IoT sensors and field validation to improve urban mobility in Marawi City, with an MSU-Marawi counterpart.',
                'funder' => 'MOST-BARMM',
                'budget' => 3178376.00,
                'counterpart' => 1178376.00,
                'start' => $today->copy()->subMonths(10)->startOfMonth(),
                'end' => $today->copy()->addMonths(2)->endOfMonth(),
                'leader_email' => 'project.leader@gmail.com',
                'scenario' => 'overdue',
                'line_items' => [
                    ['PS-01', 'PS', 'Project Leader Honoraria', 400000.00],
                    ['PS-02', 'PS', 'Research Assistant Honoraria', 360000.00],
                    ['PS-03', 'PS', 'Program Leader Indirect Honoraria', 200000.00],
                    ['MOOE-01', 'MOOE', 'Traveling Expenses', 350000.00],
                    ['MOOE-02', 'MOOE', 'Supplies & Materials (IoT sensors, laptops)', 700000.00],
                    ['MOOE-03', 'MOOE', 'Communication Expenses', 120000.00],
                    ['MOOE-04', 'MOOE', 'Professional Services (programmers/enumerators)', 500000.00],
                    ['MOOE-05', 'MOOE', 'Cloud/Server Rent', 200000.00],
                    ['MOOE-06', 'MOOE', 'Utilities', 348376.00],
                ],
                'tranches' => [
                    ['planned' => $today->copy()->subMonths(9), 'released' => true],
                    ['planned' => $today->copy()->subMonths(5), 'released' => true],
                    ['planned' => $today->copy()->subMonths(1), 'released' => false],
                    ['planned' => $today->copy()->addMonths(2), 'released' => false],
                ],
                'expenses' => [
                    ['URB-2026-001', 'PS-01', 'Project Leader honoraria — Year 1', 400000.00, 9, 'verified', true],
                    ['URB-2026-002', 'PS-02', 'Research Assistant honoraria — Year 1', 180000.00, 8, 'verified', true],
                    ['URB-2026-003', 'MOOE-01', 'Field validation travel — Marawi clusters', 200000.00, 7, 'verified', true],
                    ['URB-2026-004', 'MOOE-02', 'IoT sensor kits and laptops', 350000.00, 6, 'verified', true],
                    ['URB-2026-005', 'MOOE-04', 'Programmers and enumerators', 150000.00, 4, 'verified', true],
                    ['URB-2026-006', 'MOOE-03', 'Mobile data and communication load', 50000.00, 3, 'verified', false],
                    ['URB-2026-007', 'MOOE-06', 'Project office utilities', 100000.00, 2, 'verified', true],
                    ['URB-2026-008', 'MOOE-02', 'Additional sensor enclosures', 75000.00, 1, 'pending', true],
                    ['URB-2026-009', 'MOOE-01', 'Cebu field coordination travel', 40000.00, 0, 'pending', false],
                    ['URB-2026-010', 'MOOE-02', 'Unsupported equipment purchase', 25000.00, 1, 'rejected', false],
                ],
                'liquidations' => [
                    [1, 'approved', 794594.00],
                    [2, 'approved', 794594.00],
                ],
                'reports' => [
                    ['progress', 'Progress Report — Q1', -3, -2],
                    ['financial', 'Financial Report — Midyear', 10, null],
                    ['terminal', 'Terminal Report', 'end', null],
                ],
            ],
            [
                'key' => 'goat',
                'code' => 'MOST-RD-2026-002',
                'title' => 'Halal Goat Value Chain Development, Lanao del Sur',
                'description' => 'DOST-PCAARRD project strengthening the halal goat value chain through production, processing, and market linkage studies.',
                'funder' => 'DOST-PCAARRD',
                'budget' => 1870000.00,
                'counterpart' => 0.00,
                'start' => $today->copy()->subMonths(8)->startOfMonth(),
                'end' => $today->copy()->addMonths(4)->endOfMonth(),
                'leader_email' => 'project.leader2@gmail.com',
                'scenario' => 'near_depletion',
                'line_items' => [
                    ['PS-01', 'PS', 'Project Leader Honoraria', 300000.00],
                    ['PS-02', 'PS', 'Research Assistant Honoraria', 240000.00],
                    ['PS-03', 'PS', 'Researcher Honoraria', 160000.00],
                    ['MOOE-01', 'MOOE', 'Traveling Expenses', 220000.00],
                    ['MOOE-02', 'MOOE', 'Supplies & Materials', 600000.00],
                    ['MOOE-03', 'MOOE', 'Professional Services', 250000.00],
                    ['MOOE-04', 'MOOE', 'Communication Expenses', 100000.00],
                ],
                'tranches' => [
                    ['planned' => $today->copy()->subMonths(7), 'released' => true],
                    ['planned' => $today->copy()->subMonths(3), 'released' => true],
                    ['planned' => $today->copy()->addMonths(1), 'released' => false],
                    ['planned' => $today->copy()->addMonths(4), 'released' => false],
                ],
                'expenses' => [
                    ['GOAT-2026-001', 'PS-01', 'Project Leader honoraria', 300000.00, 7, 'verified', true],
                    ['GOAT-2026-002', 'PS-02', 'Research Assistant honoraria', 240000.00, 6, 'verified', true],
                    ['GOAT-2026-003', 'PS-03', 'Researcher honoraria', 160000.00, 5, 'verified', true],
                    ['GOAT-2026-004', 'MOOE-01', 'Household survey travel', 200000.00, 4, 'verified', true],
                    ['GOAT-2026-005', 'MOOE-02', 'Goat production inputs and feed', 600000.00, 3, 'verified', true],
                    ['GOAT-2026-006', 'MOOE-03', 'Processing facility services', 215000.00, 2, 'verified', true],
                    ['GOAT-2026-007', 'MOOE-04', 'Field communication load', 80000.00, 1, 'verified', true],
                    ['GOAT-2026-008', 'MOOE-02', 'Supplemental feed', 30000.00, 0, 'pending', true],
                    ['GOAT-2026-009', 'MOOE-01', 'Municipal coordination travel', 10000.00, 0, 'pending', false],
                    ['GOAT-2026-010', 'MOOE-04', 'Partner internet subscription', 5000.00, 0, 'pending', true],
                    ['GOAT-2026-011', 'MOOE-03', 'Unsupported consultancy', 40000.00, 1, 'rejected', false],
                ],
                'liquidations' => [
                    [1, 'approved', 467500.00],
                    [2, 'approved', 467500.00],
                ],
                'reports' => [
                    ['progress', 'Progress Report — Midyear', -2, -1],
                    ['financial', 'Financial Report — Q3', 20, null],
                    ['terminal', 'Terminal Report', 'end', null],
                ],
            ],
            [
                'key' => 'nutrition',
                'code' => 'MOST-RD-2026-003',
                'title' => 'Community Nutrition Surveillance, Marawi Resettlement Sites',
                'description' => 'CHED-funded project tracking nutrition outcomes in Marawi resettlement communities and strengthening local health surveillance.',
                'funder' => 'CHED',
                'budget' => 1210000.00,
                'counterpart' => 0.00,
                'start' => $today->copy()->subMonths(5)->startOfMonth(),
                'end' => $today->copy()->addMonths(7)->endOfMonth(),
                'leader_email' => 'project.leader4@gmail.com',
                'scenario' => 'under_spending',
                'line_items' => [
                    ['PS-01', 'PS', 'Project Leader Honoraria', 250000.00],
                    ['PS-02', 'PS', 'Research Assistant Honoraria', 200000.00],
                    ['PS-03', 'PS', 'Researcher Honoraria', 150000.00],
                    ['MOOE-01', 'MOOE', 'Traveling Expenses', 200000.00],
                    ['MOOE-02', 'MOOE', 'Supplies & Materials', 250000.00],
                    ['MOOE-03', 'MOOE', 'Professional Services', 100000.00],
                    ['MOOE-04', 'MOOE', 'Communication Expenses', 60000.00],
                ],
                'tranches' => [
                    ['planned' => $today->copy()->subMonths(4), 'released' => true],
                    ['planned' => $today->copy()->addMonths(2), 'released' => false],
                    ['planned' => $today->copy()->addMonths(5), 'released' => false],
                    ['planned' => $today->copy()->addMonths(8), 'released' => false],
                ],
                'expenses' => [
                    ['NUT-2026-001', 'PS-01', 'Project Leader honoraria — start-up', 100000.00, 4, 'verified', true],
                    ['NUT-2026-002', 'PS-02', 'Research Assistant honoraria', 50000.00, 3, 'verified', false],
                    ['NUT-2026-003', 'MOOE-01', 'Resettlement site travel', 30000.00, 2, 'verified', true],
                    ['NUT-2026-004', 'MOOE-02', 'Anthropometric supplies', 25000.00, 1, 'verified', true],
                    ['NUT-2026-005', 'MOOE-03', 'Data encoding services', 12800.00, 1, 'verified', true],
                    ['NUT-2026-006', 'MOOE-02', 'Additional field forms', 15000.00, 0, 'pending', true],
                    ['NUT-2026-007', 'MOOE-01', 'Barangay coordination travel', 5000.00, 0, 'pending', false],
                    ['NUT-2026-008', 'PS-02', 'Unsupported honorarium adjustment', 25000.00, 0, 'rejected', false],
                ],
                'liquidations' => [
                    [1, 'pending', 302500.00],
                ],
                'reports' => [
                    ['progress', 'Progress Report — Baseline', -3, -2],
                    ['financial', 'Financial Report — Q3', 5, null],
                    ['terminal', 'Terminal Report', 'end', null],
                ],
            ],
            [
                'key' => 'weaving',
                'code' => 'MOST-RD-2025-001',
                'title' => 'Meranaw Weaving Heritage Digital Archive',
                'description' => 'MOST-BARMM project digitizing Meranaw weaving heritage. The project is completed and every released tranche has been fully liquidated.',
                'funder' => 'MOST-BARMM',
                'budget' => 940000.00,
                'counterpart' => 0.00,
                'start' => $today->copy()->subMonths(18)->startOfMonth(),
                'end' => $today->copy()->subMonths(3)->endOfMonth(),
                'leader_email' => 'project.leader3@gmail.com',
                'scenario' => 'completed',
                'line_items' => [
                    ['PS-01', 'PS', 'Project Leader Honoraria', 200000.00],
                    ['PS-02', 'PS', 'Research Assistant Honoraria', 120000.00],
                    ['PS-03', 'PS', 'Researcher Honoraria', 80000.00],
                    ['MOOE-01', 'MOOE', 'Traveling Expenses', 150000.00],
                    ['MOOE-02', 'MOOE', 'Supplies & Materials', 250000.00],
                    ['MOOE-03', 'MOOE', 'Professional Services', 90000.00],
                    ['MOOE-04', 'MOOE', 'Communication Expenses', 50000.00],
                ],
                'tranches' => [
                    ['planned' => $today->copy()->subMonths(16), 'released' => true],
                    ['planned' => $today->copy()->subMonths(12), 'released' => true],
                    ['planned' => $today->copy()->subMonths(8), 'released' => true],
                    ['planned' => $today->copy()->subMonths(4), 'released' => true],
                ],
                'expenses' => [
                    ['WEAVE-2025-001', 'PS-01', 'Project Leader honoraria', 200000.00, 16, 'verified', true],
                    ['WEAVE-2025-002', 'PS-02', 'Research Assistant honoraria', 120000.00, 15, 'verified', true],
                    ['WEAVE-2025-003', 'PS-03', 'Researcher honoraria', 80000.00, 14, 'verified', true],
                    ['WEAVE-2025-004', 'MOOE-01', 'Weaving community travel', 100000.00, 12, 'verified', true],
                    ['WEAVE-2025-005', 'MOOE-01', 'Archive turnover travel', 50000.00, 11, 'verified', true],
                    ['WEAVE-2025-006', 'MOOE-02', 'Recording equipment', 150000.00, 10, 'verified', true],
                    ['WEAVE-2025-007', 'MOOE-02', 'Archival storage materials', 100000.00, 9, 'verified', true],
                    ['WEAVE-2025-008', 'MOOE-03', 'Digitization services', 60000.00, 7, 'verified', true],
                    ['WEAVE-2025-009', 'MOOE-03', 'Metadata consultancy', 30000.00, 6, 'verified', true],
                    ['WEAVE-2025-010', 'MOOE-04', 'Communication and dissemination', 50000.00, 5, 'verified', true],
                ],
                'liquidations' => [
                    [1, 'approved', 235000.00],
                    [2, 'approved', 235000.00],
                    [3, 'approved', 235000.00],
                    [4, 'approved', 235000.00],
                ],
                'reports' => [
                    ['progress', 'Progress Report — Final', -6, -5],
                    ['financial', 'Financial Report — Final', -4, -3],
                    ['terminal', 'Terminal Report', 'end', -2],
                ],
            ],
        ];
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: float}> $items
     * @return \Illuminate\Support\Collection<string, LibCategory>
     */
    private function seedLineItems(Project $project, array $items): \Illuminate\Support\Collection
    {
        foreach ($items as [$code, $type, $name, $amount]) {
            LibCategory::updateOrCreate(
                ['project_id' => $project->id, 'line_item_code' => $code],
                [
                    'category_type' => $type,
                    'line_item_name' => $name,
                    'allocated_amount' => $amount,
                ]
            );
        }

        return LibCategory::where('project_id', $project->id)->get()->keyBy('line_item_code');
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function seedTranches(Project $project, array $definition, Carbon $today): void
    {
        $names = [
            1 => 'Tranche 1 — Mobilization',
            2 => 'Tranche 2 — Midyear Review',
            3 => 'Tranche 3 — Field Validation',
            4 => 'Tranche 4 — Closeout',
        ];
        $amount = round($definition['budget'] / 4, 2);

        foreach ($definition['tranches'] as $index => $tranche) {
            $sequence = $index + 1;
            $planned = $tranche['planned']->copy();
            $released = (bool) $tranche['released'];

            ProjectTranche::updateOrCreate(
                ['project_id' => $project->id, 'sequence' => $sequence],
                [
                    'name' => $names[$sequence],
                    'amount' => $amount,
                    'percentage' => 25.00,
                    'status' => $released ? 'released' : ($planned->lt($today) ? 'due' : 'upcoming'),
                    'planned_release_date' => $planned->toDateString(),
                    'released_at' => $released ? $planned->copy()->addDays(5)->toDateString() : null,
                    'notes' => $released ? 'Released after milestone acceptance.' : null,
                ]
            );
        }
    }

    /**
     * @param \Illuminate\Support\Collection<string, LibCategory> $categories
     * @param array<string, mixed> $definition
     */
    private function seedExpenses(
        Project $project,
        \Illuminate\Support\Collection $categories,
        array $definition,
        User $ra,
        User $finance
    ): void {
        $today = now()->startOfDay();

        foreach ($definition['expenses'] as [$ref, $lineCode, $description, $amount, $monthsAgo, $status, $hasReceipt]) {
            $category = $categories->get($lineCode);
            if (! $category) {
                continue;
            }

            $date = $monthsAgo > 0
                ? $today->copy()->subMonths($monthsAgo)->addDays(8)
                : $today->copy()->subDays(5);
            if ($date->greaterThan($today)) {
                $date = $today->copy();
            }

            Expense::updateOrCreate(
                ['project_id' => $project->id, 'reference_no' => $ref],
                [
                    'lib_category_id' => $category->id,
                    'recorded_by' => $ra->id,
                    'verified_by' => in_array($status, ['verified', 'rejected'], true) ? $finance->id : null,
                    'payee' => $this->demoPayee($project, $category->line_item_name),
                    'description' => $description,
                    'amount' => $amount,
                    'expense_date' => $date->toDateString(),
                    'attachment_path' => $hasReceipt ? 'receipts/' . $project->code . '/' . $ref . '.pdf' : null,
                    'status' => $status,
                    'remarks' => $status === 'rejected' ? 'Rejected: documentation does not support the charge.' : null,
                    'verified_at' => in_array($status, ['verified', 'rejected'], true)
                        ? $this->clamp($date->copy()->addDay())
                        : null,
                ]
            );
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, ProjectTranche> $tranches
     * @return \Illuminate\Support\Collection<int, Liquidation>
     */
    private function seedLiquidations(
        Project $project,
        array $definition,
        \Illuminate\Support\Collection $tranches,
        User $leader,
        User $programLeader,
        Carbon $today
    ): \Illuminate\Support\Collection {
        $liquidations = collect();

        foreach ($definition['liquidations'] as [$sequence, $status, $amount]) {
            $tranche = $tranches->get($sequence);
            $due = $tranche?->planned_release_date
                ? $tranche->planned_release_date->copy()->addDays(30)
                : $today->copy()->addDays(30);
            $submitted = $this->clamp($due->copy()->subDays(5));

            $liquidation = Liquidation::updateOrCreate(
                ['project_id' => $project->id, 'title' => 'Liquidation Report — Tranche ' . $sequence],
                [
                    'project_tranche_id' => $tranche?->id,
                    'amount' => $amount,
                    'due_date' => $due->toDateString(),
                    'submitted_at' => $submitted->toDateString(),
                    'status' => $status,
                    'document_path' => 'liquidations/' . $project->code . '/tranche-' . $sequence . '.pdf',
                    'remarks' => $status === 'approved' ? 'Liquidation accepted; receipts complete.' : null,
                    'reviewed_by' => $status === 'approved' ? $programLeader->id : null,
                    'reviewed_at' => $status === 'approved' ? $this->clamp($submitted->copy()->addDays(2)) : null,
                ]
            );

            $liquidations->put($sequence, $liquidation);
        }

        return $liquidations;
    }

    /**
     * @param \Illuminate\Support\Collection<int, ProjectTranche> $tranches
     * @param \Illuminate\Support\Collection<string, LibCategory> $categories
     * @param \Illuminate\Support\Collection<int, Liquidation> $liquidations
     */
    private function seedApprovals(
        Project $project,
        array $definition,
        \Illuminate\Support\Collection $tranches,
        \Illuminate\Support\Collection $categories,
        \Illuminate\Support\Collection $liquidations,
        User $leader,
        User $programLeader,
        Carbon $today
    ): void {
        if ($definition['scenario'] === 'overdue') {
            $tranche = $tranches->get(3);
            ApprovalRequest::updateOrCreate(
                ['project_id' => $project->id, 'title' => 'Release Tranche 3 — Field Validation'],
                [
                    'project_tranche_id' => $tranche?->id,
                    'type' => 'disbursement',
                    'description' => 'Field validation is complete; request release of the third tranche.',
                    'justification' => 'Validation visits in all partner barangays are done and enumerators must be paid before the analysis phase begins.',
                    'amount' => $tranche?->amount,
                    'requested_by' => $leader->id,
                    'status' => 'pending',
                    'created_at' => $this->clamp($today->copy()->subDays(20)),
                    'updated_at' => $this->clamp($today->copy()->subDays(20)),
                ]
            );
        }

        if ($definition['scenario'] === 'near_depletion') {
            $from = $categories->get('MOOE-04');
            $to = $categories->get('MOOE-01');
            ApprovalRequest::updateOrCreate(
                ['project_id' => $project->id, 'title' => 'Realign ₱15,000 to Field Travel'],
                [
                    'type' => 'budget_realignment',
                    'description' => 'Move unused communication allocation to field travel for the final survey round.',
                    'justification' => 'Fuel and boat fares for the final survey round exceed the travel line, while the communication line still has unused funds.',
                    'amount' => 15000.00,
                    'payload' => [
                        'from_lib_category_id' => $from?->id,
                        'to_lib_category_id' => $to?->id,
                        'amount' => 15000.00,
                        'reason' => 'Field travel is over the original estimate; communication savings can cover it.',
                    ],
                    'requested_by' => $leader->id,
                    'status' => 'pending',
                    'created_at' => $this->clamp($today->copy()->subDays(12)),
                    'updated_at' => $this->clamp($today->copy()->subDays(12)),
                ]
            );
        }

        if ($definition['scenario'] === 'under_spending') {
            $tranche = $tranches->get(1);
            $liquidation = $liquidations->get(1);
            ApprovalRequest::updateOrCreate(
                ['project_id' => $project->id, 'title' => 'Liquidation Report — Tranche 1'],
                [
                    'project_tranche_id' => $tranche?->id,
                    'liquidation_id' => $liquidation?->id,
                    'type' => 'liquidation',
                    'description' => 'Submit the first liquidation report for review.',
                    'justification' => 'Receipts for the first tranche are complete and liquidating them is required before the next tranche can be requested.',
                    'amount' => $liquidation?->amount,
                    'requested_by' => $leader->id,
                    'status' => 'pending',
                    'created_at' => $this->clamp($today->copy()->subDays(6)),
                    'updated_at' => $this->clamp($today->copy()->subDays(6)),
                ]
            );
        }

        // Historical approved liquidations for the completed project and
        // released tranches, so the audit trail has approval decisions.
        foreach ($liquidations as $sequence => $liquidation) {
            if ($liquidation->status !== 'approved') {
                continue;
            }

            $approval = ApprovalRequest::updateOrCreate(
                ['project_id' => $project->id, 'title' => 'Liquidation Report — Tranche ' . $sequence],
                [
                    'project_tranche_id' => $liquidation->project_tranche_id,
                    'liquidation_id' => $liquidation->id,
                    'type' => 'liquidation',
                    'description' => 'Historical liquidation approval.',
                    'justification' => 'Official receipts and the expense summary for this tranche were submitted to close out its disbursement.',
                    'amount' => $liquidation->amount,
                    'requested_by' => $leader->id,
                    'reviewed_by' => $programLeader->id,
                    'status' => 'approved',
                    'review_notes' => 'Approved after receipt review.',
                    'reviewed_at' => $liquidation->reviewed_at ?? $this->clamp($today->copy()->subDays(10)),
                    'created_at' => $liquidation->submitted_at
                        ? Carbon::parse($liquidation->submitted_at)->startOfDay()
                        : $this->clamp($today->copy()->subDays(15)),
                    'updated_at' => $liquidation->reviewed_at ?? $this->clamp($today->copy()->subDays(10)),
                ]
            );

            // The pending liquidation request for the under-spending project
            // is the same title; do not turn it into an approved one.
            if ($definition['scenario'] === 'under_spending') {
                $approval->update(['status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null]);
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function seedReports(Project $project, array $definition, Carbon $today): void
    {
        $end = $definition['end'];

        foreach ($definition['reports'] as [$type, $title, $dueOffset, $submittedOffset]) {
            $due = $dueOffset === 'end' ? $end->copy() : $today->copy()->addDays((int) $dueOffset);
            $submitted = $submittedOffset === null
                ? null
                : $this->clamp($today->copy()->addDays((int) $submittedOffset));

            ProjectReport::updateOrCreate(
                ['project_id' => $project->id, 'title' => $title],
                [
                    'type' => $type,
                    'due_date' => $due->toDateString(),
                    'submitted_at' => $submitted?->toDateString(),
                    'document_path' => $submitted ? 'reports/' . $project->code . '/' . $type . '.pdf' : null,
                ]
            );
        }
    }

    /**
     * @param array<int, User> $users
     * @param \Illuminate\Support\Collection<string, \App\Models\Role> $roles
     */
    private function assignTeam(Project $project, \Illuminate\Support\Collection $roles, array $users): void
    {
        $assignments = [
            [$users[0], 'program-leader'],
            [$users[1], 'project-leader'],
            [$users[2], 'research-assistant'],
            [$users[3], 'finance-admin'],
            [$users[4], 'researcher'],
        ];

        foreach ($assignments as [$user, $slug]) {
            $role = $roles->get($slug);
            if (! $user || ! $role) {
                continue;
            }

            DB::table('project_user')->updateOrInsert(
                ['project_id' => $project->id, 'user_id' => $user->id, 'role_id' => $role->id],
                ['assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    private function seedAuditTrail(Project $project, User $programLeader, User $ra, User $finance): void
    {
        $this->audit(
            $programLeader->id,
            'created',
            'Project',
            $project->id,
            null,
            ['code' => $project->code, 'title' => $project->title, 'budget' => (float) $project->total_approved_budget],
            $project->created_at ?? now()
        );

        foreach ($project->expenses()->orderBy('expense_date')->get() as $expense) {
            $created = $this->clamp($expense->expense_date->copy()->subDay());
            $this->audit(
                $ra->id,
                'created',
                'Expense',
                $expense->id,
                null,
                ['reference_no' => $expense->reference_no, 'status' => 'pending', 'amount' => (float) $expense->amount],
                $created
            );

            if ($expense->status === 'verified') {
                $this->audit(
                    $finance->id,
                    'verified',
                    'Expense',
                    $expense->id,
                    ['status' => 'pending'],
                    ['status' => 'verified'],
                    $this->clamp($expense->expense_date->copy()->addDay())
                );
            }

            if ($expense->status === 'rejected') {
                $this->audit(
                    $finance->id,
                    'rejected',
                    'Expense',
                    $expense->id,
                    ['status' => 'pending'],
                    ['status' => 'rejected', 'reason' => $expense->remarks],
                    $this->clamp($expense->expense_date->copy()->addDays(2))
                );
            }
        }

        foreach ($project->tranches()->get() as $tranche) {
            if ($tranche->status === 'released' && $tranche->released_at) {
                $this->audit(
                    $programLeader->id,
                    'approved',
                    'ProjectTranche',
                    $tranche->id,
                    ['status' => 'due'],
                    ['status' => 'released'],
                    Carbon::parse($tranche->released_at)->startOfDay()
                );
            }
        }

        foreach ($project->liquidations()->get() as $liquidation) {
            $this->audit(
                $liquidation->project?->usersWithRole('project-leader')->first()?->id ?? $programLeader->id,
                'requested',
                'Liquidation',
                $liquidation->id,
                null,
                ['status' => 'pending', 'amount' => (float) $liquidation->amount],
                $liquidation->submitted_at
                    ? Carbon::parse($liquidation->submitted_at)->startOfDay()
                    : $this->clamp(now()->subDays(10))
            );

            if ($liquidation->status === 'approved') {
                $this->audit(
                    $programLeader->id,
                    'approved',
                    'Liquidation',
                    $liquidation->id,
                    ['status' => 'pending'],
                    ['status' => 'approved'],
                    $liquidation->reviewed_at ?? $this->clamp(now()->subDays(5))
                );
            }
        }

        foreach ($project->approvalRequests()->get() as $approval) {
            $this->audit(
                $approval->requested_by,
                'requested',
                'ApprovalRequest',
                $approval->id,
                null,
                ['type' => $approval->type, 'status' => 'pending', 'amount' => (float) $approval->amount],
                $approval->created_at ?? $this->clamp(now()->subDays(7))
            );

            if ($approval->status === 'approved') {
                $this->audit(
                    $approval->reviewed_by ?? $programLeader->id,
                    'approved',
                    'ApprovalRequest',
                    $approval->id,
                    ['status' => 'pending'],
                    ['status' => 'approved'],
                    $approval->reviewed_at ?? $this->clamp(now()->subDays(3))
                );
            }
        }

        $communication = $project->libCategories()->where('line_item_code', 'MOOE-03')->first();
        if ($communication) {
            $this->audit(
                $programLeader->id,
                'updated',
                'LibCategory',
                $communication->id,
                ['allocated_amount' => (float) $communication->allocated_amount - 20000],
                ['allocated_amount' => (float) $communication->allocated_amount],
                $this->clamp(now()->subDays(30)),
                'Budget change approved: realign ₱20,000 from utilities to communication after the midyear review.'
            );
        }
    }

    private function audit(
        ?int $userId,
        string $action,
        string $modelType,
        int $modelId,
        ?array $oldValues,
        ?array $newValues,
        Carbon $createdAt,
        ?string $reason = null
    ): void {
        if ($reason !== null) {
            $newValues = array_merge($newValues ?? [], ['reason' => $reason]);
        }

        AuditTrail::create([
            'user_id' => $userId,
            'action' => $action,
            'model_type' => $modelType,
            'model_id' => $modelId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => '127.0.0.1',
            'created_at' => $this->clamp($createdAt),
        ]);
    }

    private function resetSeedAuditRows(array $codes): void
    {
        $projectIds = Project::whereIn('code', $codes)->pluck('id')->all();
        if ($projectIds === []) {
            return;
        }

        $map = [
            'Project' => $projectIds,
            'Expense' => Expense::whereIn('project_id', $projectIds)->pluck('id')->all(),
            'ProjectTranche' => ProjectTranche::whereIn('project_id', $projectIds)->pluck('id')->all(),
            'ApprovalRequest' => ApprovalRequest::whereIn('project_id', $projectIds)->pluck('id')->all(),
            'Liquidation' => Liquidation::whereIn('project_id', $projectIds)->pluck('id')->all(),
            'LibCategory' => LibCategory::whereIn('project_id', $projectIds)->pluck('id')->all(),
            'ProjectReport' => ProjectReport::whereIn('project_id', $projectIds)->pluck('id')->all(),
        ];

        foreach ($map as $type => $ids) {
            if ($ids !== []) {
                AuditTrail::where('model_type', $type)->whereIn('model_id', $ids)->delete();
            }
        }
    }

    private function derivedStatus(Carbon $start, Carbon $end): string
    {
        $today = now()->startOfDay();

        if ($start->greaterThan($today)) {
            return 'planning';
        }

        if ($end->lessThan($today)) {
            return 'completed';
        }

        return 'ongoing';
    }

    private function clamp(Carbon $date): Carbon
    {
        $now = now();
        return $date->greaterThan($now) ? $now->copy() : $date->copy();
    }
}
