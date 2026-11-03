<?php

namespace Database\Seeders;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Liquidation;
use App\Models\Project;
use App\Models\ProjectReport;
use App\Models\ProjectTranche;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Realistic program-wide demo data for the Program Leader dashboard.
 *
 * Four projects cover the three demo scenarios:
 * - MOST-RD-2026-001 has an overdue tranche;
 * - RP-2026-021 under-spends against its timeline;
 * - RP-2025-031 is nearly depleted and shows a Final pending tranche.
 *
 * Every write is updateOrCreate so re-running the seeder is safe.
 */
class ProgramDashboardSeeder extends Seeder
{
    public function run(): void
    {
        $most = FundingAgency::updateOrCreate(
            ['acronym' => 'MOST-BARMM'],
            ['name' => 'MOST – BARMM']
        );
        $dost = FundingAgency::updateOrCreate(
            ['acronym' => 'DOST'],
            ['name' => 'Department of Science and Technology – PCAARRD']
        );
        $ched = FundingAgency::updateOrCreate(
            ['acronym' => 'CHED'],
            ['name' => 'Commission on Higher Education']
        );
        $pchrd = FundingAgency::updateOrCreate(
            ['acronym' => 'DOST-PCHRD'],
            ['name' => 'DOST – Philippine Council for Health Research and Development']
        );

        $projects = [
            'MOST-RD-2026-001' => [
                'title' => 'Enhancing Urban Mobility',
                'description' => 'Line-Item Budget (Form B) project for enhancing urban mobility in Marawi City.',
                'funding_agency_id' => $most->id,
                'total_approved_budget' => 3178376.00,
                'implementing_agency_counterpart' => 1178376.00,
                'funding_agency_amount' => 2000000.00,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'ongoing',
            ],
            'RP-2026-009' => [
                'title' => 'Halal Goat Value Chain Development',
                'description' => 'DOST-PCAARRD project on halal goat value chain development in Lanao del Sur.',
                'funding_agency_id' => $dost->id,
                'total_approved_budget' => 1870000.00,
                'implementing_agency_counterpart' => 0.00,
                'funding_agency_amount' => 1870000.00,
                'start_date' => '2026-03-01',
                'end_date' => '2027-02-28',
                'status' => 'ongoing',
            ],
            'RP-2026-021' => [
                'title' => 'Community Nutrition Surveillance',
                'description' => 'DOST-PCHRD surveillance project in Marawi resettlement sites.',
                'funding_agency_id' => $pchrd->id,
                'total_approved_budget' => 1210000.00,
                'implementing_agency_counterpart' => 0.00,
                'funding_agency_amount' => 1210000.00,
                'start_date' => '2026-06-15',
                'end_date' => '2027-06-14',
                'status' => 'ongoing',
            ],
            'RP-2025-031' => [
                'title' => 'Meranaw Weaving Heritage Archive',
                'description' => 'CHED project digitizing Meranaw weaving heritage.',
                'funding_agency_id' => $ched->id,
                'total_approved_budget' => 940000.00,
                'implementing_agency_counterpart' => 0.00,
                'funding_agency_amount' => 940000.00,
                'start_date' => '2025-06-01',
                'end_date' => '2026-05-31',
                'status' => 'completed',
            ],
        ];

        foreach ($projects as $code => $data) {
            Project::updateOrCreate(['code' => $code], $data);
        }

        $urban = Project::where('code', 'MOST-RD-2026-001')->first();
        $goat = Project::where('code', 'RP-2026-009')->first();
        $nutrition = Project::where('code', 'RP-2026-021')->first();
        $weaving = Project::where('code', 'RP-2025-031')->first();

        $recorder = User::where('email', 'assistant@gmail.com')->first()
            ?? User::query()->orderBy('id')->first();
        $verifier = User::where('email', 'finance@gmail.com')->first()
            ?? User::query()->orderBy('id')->first();
        $requester = User::where('email', 'project.leader@gmail.com')->first()
            ?? User::query()->orderBy('id')->first();

        if (! $urban || ! $goat || ! $nutrition || ! $weaving || ! $recorder || ! $requester) {
            return;
        }

        $this->seedGoat($goat, $recorder, $verifier, $requester);
        $this->seedNutrition($nutrition, $recorder, $verifier, $requester);
        $this->seedWeaving($weaving, $recorder, $verifier);
        $this->seedUrban($urban, $recorder, $verifier, $requester);
        $this->seedUrbanReportsAndLiquidations($urban, $requester);
        $this->seedUrbanApprovalDates($urban);
    }

    protected function seedGoat(Project $project, User $recorder, ?User $verifier, User $requester): void
    {
        $categories = [
            ['PS-01', 'PS', 'Project Leader Honoraria', 500000],
            ['PS-02', 'PS', 'Research Assistant Honoraria', 300000],
            ['PS-03', 'PS', 'Researcher Honoraria', 200000],
            ['MOOE-01', 'MOOE', 'Traveling Expenses', 300000],
            ['MOOE-02', 'MOOE', 'Supplies & Materials', 370000],
            ['MOOE-03', 'MOOE', 'Professional Services', 200000],
        ];
        $this->seedCategories($project, $categories);

        $expenses = [];
        for ($index = 1; $index <= 12; $index++) {
            $expenses[] = [
                'ref' => 'GOAT-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'category' => $categories[($index - 1) % count($categories)][0],
                'amount' => 126225.00,
                'date' => sprintf('2026-%02d-15', min(12, 3 + $index)),
                'receipt' => $index !== 12,
                'description' => 'Halal goat value chain field activity ' . $index,
            ];
        }
        $this->seedExpenses($project, $expenses, $recorder, $verifier);

        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 1],
            [
                'name' => 'Tranche 1 – Mobilization',
                'amount' => 748000,
                'percentage' => 40,
                'status' => 'released',
                'planned_release_date' => '2026-03-15',
                'released_at' => '2026-03-20',
            ]
        );
        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 2],
            [
                'name' => 'Tranche 2 – Mid-year Milestone',
                'amount' => 561000,
                'percentage' => 30,
                'status' => 'released',
                'planned_release_date' => '2026-07-15',
                'released_at' => '2026-07-22',
            ]
        );
        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 3],
            [
                'name' => 'Tranche 3 – Field Validation',
                'amount' => 374000,
                'percentage' => 20,
                'status' => 'upcoming',
                'planned_release_date' => '2026-12-20',
                'released_at' => null,
            ]
        );
        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 4],
            [
                'name' => 'Tranche 4 – Final Report',
                'amount' => 187000,
                'percentage' => 10,
                'status' => 'upcoming',
                'planned_release_date' => '2027-02-15',
                'released_at' => null,
            ]
        );

        ProjectReport::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Progress report – Q3 2026'],
            [
                'type' => 'progress',
                'due_date' => '2026-11-15',
                'submitted_at' => null,
            ]
        );

        $liquidation = Liquidation::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Liquidation – Tranche 2'],
            [
                'project_tranche_id' => $project->tranches()->where('sequence', 2)->value('id'),
                'amount' => 512300,
                'due_date' => '2026-11-30',
                'submitted_at' => '2026-11-01',
                'status' => 'pending',
            ]
        );

        $this->approval($project, $requester, [
            'title' => 'Liquidation report – Tranche 2',
            'type' => 'liquidation',
            'amount' => 512300,
            'description' => 'Submitted liquidation covering tranche 2 field activities.',
            'liquidation_id' => $liquidation->id,
        ], 3);
    }

    protected function seedNutrition(Project $project, User $recorder, ?User $verifier, User $requester): void
    {
        $categories = [
            ['PS-01', 'PS', 'Project Leader Honoraria', 300000],
            ['PS-02', 'PS', 'Research Assistant Honoraria', 200000],
            ['PS-03', 'PS', 'Researcher Honoraria', 150000],
            ['MOOE-01', 'MOOE', 'Traveling Expenses', 150000],
            ['MOOE-02', 'MOOE', 'Supplies & Materials', 200000],
            ['MOOE-03', 'MOOE', 'Professional Services', 110000],
            ['MOOE-04', 'MOOE', 'Cloud/Server Rent', 100000],
        ];
        $this->seedCategories($project, $categories);

        $expenses = [];
        for ($index = 1; $index <= 6; $index++) {
            $expenses[] = [
                'ref' => 'NUTRI-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'category' => $categories[($index - 1) % count($categories)][0],
                'amount' => 24805.00,
                'date' => sprintf('2026-%02d-18', min(12, 7 + $index)),
                'receipt' => $index <= 5,
                'description' => 'Nutrition surveillance field expense ' . $index,
            ];
        }
        $this->seedExpenses($project, $expenses, $recorder, $verifier);

        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 1],
            [
                'name' => 'Tranche 1 – Mobilization',
                'amount' => 484000,
                'percentage' => 40,
                'status' => 'released',
                'planned_release_date' => '2026-07-01',
                'released_at' => '2026-07-08',
            ]
        );
        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 2],
            [
                'name' => 'Tranche 2 – Mid-year Milestone',
                'amount' => 363000,
                'percentage' => 30,
                'status' => 'upcoming',
                'planned_release_date' => '2026-12-01',
                'released_at' => null,
            ]
        );
        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 3],
            [
                'name' => 'Tranche 3 – Field Validation',
                'amount' => 242000,
                'percentage' => 20,
                'status' => 'upcoming',
                'planned_release_date' => '2027-03-01',
                'released_at' => null,
            ]
        );
        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 4],
            [
                'name' => 'Tranche 4 – Final Report',
                'amount' => 121000,
                'percentage' => 10,
                'status' => 'upcoming',
                'planned_release_date' => '2027-06-01',
                'released_at' => null,
            ]
        );

        ProjectReport::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Progress report – Q2 2026'],
            [
                'type' => 'progress',
                'due_date' => '2026-10-30',
                'submitted_at' => null,
            ]
        );

        Liquidation::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Liquidation – Tranche 1'],
            [
                'project_tranche_id' => $project->tranches()->where('sequence', 1)->value('id'),
                'amount' => 148830,
                'due_date' => '2026-12-15',
                'submitted_at' => '2026-10-28',
                'status' => 'pending',
            ]
        );

        $this->approval($project, $requester, [
            'title' => 'Release Tranche 2 – Mid-year Milestone',
            'type' => 'disbursement',
            'amount' => 363000,
            'description' => 'Request for the second milestone release.',
            'project_tranche_id' => $project->tranches()->where('sequence', 2)->value('id'),
        ], 12);
    }

    protected function seedWeaving(Project $project, User $recorder, ?User $verifier): void
    {
        $categories = [
            ['PS-01', 'PS', 'Project Leader Honoraria', 250000],
            ['PS-02', 'PS', 'Research Assistant Honoraria', 200000],
            ['MOOE-01', 'MOOE', 'Traveling Expenses', 200000],
            ['MOOE-02', 'MOOE', 'Supplies & Materials', 190000],
            ['MOOE-03', 'MOOE', 'Professional Services', 100000],
        ];
        $this->seedCategories($project, $categories);

        $expenses = [
            [
                'ref' => 'WEAVE-01',
                'category' => 'PS-01',
                'amount' => 400000.00,
                'date' => '2025-08-15',
                'receipt' => true,
                'description' => 'Project leader honoraria',
            ],
            [
                'ref' => 'WEAVE-02',
                'category' => 'MOOE-02',
                'amount' => 327780.00,
                'date' => '2026-01-20',
                'receipt' => true,
                'description' => 'Archival equipment and digitization supplies',
            ],
            [
                'ref' => 'WEAVE-03',
                'category' => 'MOOE-01',
                'amount' => 200000.00,
                'date' => '2026-03-10',
                'receipt' => true,
                'description' => 'Field documentation travel',
            ],
        ];
        $this->seedExpenses($project, $expenses, $recorder, $verifier);

        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 1],
            [
                'name' => 'Tranche 1 – Mobilization',
                'amount' => 470000,
                'percentage' => 50,
                'status' => 'released',
                'planned_release_date' => '2025-06-15',
                'released_at' => '2025-06-20',
            ]
        );
        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 2],
            [
                'name' => 'Tranche 2 – Mid-year Milestone',
                'amount' => 282000,
                'percentage' => 30,
                'status' => 'released',
                'planned_release_date' => '2025-11-15',
                'released_at' => '2025-11-22',
            ]
        );
        ProjectTranche::updateOrCreate(
            ['project_id' => $project->id, 'sequence' => 3],
            [
                'name' => 'Tranche 3 – Final Report & Closeout',
                'amount' => 188000,
                'percentage' => 20,
                'status' => 'upcoming',
                'planned_release_date' => '2026-05-15',
                'released_at' => null,
            ]
        );

        ProjectReport::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Terminal report'],
            [
                'type' => 'terminal',
                'due_date' => '2026-05-31',
                'submitted_at' => null,
            ]
        );

        Liquidation::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Final liquidation'],
            [
                'project_tranche_id' => $project->tranches()->where('sequence', 2)->value('id'),
                'amount' => 927780,
                'due_date' => '2026-06-15',
                'submitted_at' => '2026-06-10',
                'status' => 'approved',
                'reviewed_at' => '2026-06-12 10:00:00',
            ]
        );
    }

    protected function seedUrban(Project $project, User $recorder, ?User $verifier, User $requester): void
    {
        $dash = "\u{2014}";
        $peso = "\u{20B1}";

        $categories = [
            ['PS-01', 'PS', 'Project Leader Honoraria (Prof. Mudzna M. Asakil)', 400000],
            ['PS-02', 'PS', 'Research Assistant Honoraria (2 personnel)', 360000],
            ['PS-03', 'PS', 'Researcher Honoraria', 240000],
            ['PS-04', 'PS', 'Program Leader Indirect Honoraria', 200000],
            ['MOOE-01', 'MOOE', 'Traveling Expenses', 350000],
            ['MOOE-02', 'MOOE', 'Supplies & Materials (IoT Sensors, Laptops)', 600000],
            ['MOOE-03', 'MOOE', 'Communication Expenses', 120000],
            ['MOOE-04', 'MOOE', 'Professional Services (Programmers/Enumerators)', 500000],
            ['MOOE-05', 'MOOE', 'Cloud/Server Rent', 200000],
            ['MOOE-06', 'MOOE', 'Utilities', 208376],
        ];
        $this->seedCategories($project, $categories);

        $expenses = [
            ['ref' => 'DEMO-PS-01', 'category' => 'PS-01', 'amount' => 260000.00, 'date' => '2026-02-15', 'receipt' => true, 'status' => 'verified', 'description' => 'Project Leader honoraria, Q1-Q3'],
            ['ref' => 'DEMO-PS-02', 'category' => 'PS-02', 'amount' => 240000.00, 'date' => '2026-03-15', 'receipt' => true, 'status' => 'verified', 'description' => 'Research Assistant honoraria'],
            ['ref' => 'DEMO-PS-03', 'category' => 'PS-03', 'amount' => 160000.00, 'date' => '2026-04-15', 'receipt' => false, 'status' => 'verified', 'description' => 'Researcher honoraria'],
            ['ref' => 'DEMO-PS-04', 'category' => 'PS-04', 'amount' => 120000.00, 'date' => '2026-09-15', 'receipt' => true, 'status' => 'pending', 'description' => 'Program Leader indirect honoraria'],
            ['ref' => 'DEMO-MOOE-01', 'category' => 'MOOE-01', 'amount' => 180000.00, 'date' => '2026-05-20', 'receipt' => true, 'status' => 'verified', 'description' => 'Field travel and validation activities'],
            ['ref' => 'DEMO-MOOE-02', 'category' => 'MOOE-02', 'amount' => 320000.00, 'date' => '2026-06-10', 'receipt' => true, 'status' => 'verified', 'description' => 'IoT sensors, laptops, and supplies'],
            ['ref' => 'DEMO-MOOE-03', 'category' => 'MOOE-03', 'amount' => 65000.00, 'date' => '2026-07-05', 'receipt' => false, 'status' => 'verified', 'description' => 'Connectivity and communication expenses'],
            ['ref' => 'DEMO-MOOE-04', 'category' => 'MOOE-04', 'amount' => 250000.00, 'date' => '2026-08-12', 'receipt' => false, 'status' => 'pending', 'description' => 'Programmers and enumerators'],
            ['ref' => 'DEMO-MOOE-05', 'category' => 'MOOE-05', 'amount' => 90000.00, 'date' => '2026-08-30', 'receipt' => true, 'status' => 'verified', 'description' => 'Cloud/server rent'],
            ['ref' => 'DEMO-MOOE-06', 'category' => 'MOOE-06', 'amount' => 45000.00, 'date' => '2026-09-20', 'receipt' => true, 'status' => 'verified', 'description' => 'Utilities and office overhead'],
        ];
        $this->seedExpenses($project, $expenses, $recorder, $verifier);

        $tranches = [
            1 => [
                'name' => "Tranche 1 {$dash} Project Mobilization",
                'percentage' => 40,
                'status' => 'released',
                'planned_release_date' => '2026-01-15',
                'released_at' => '2026-01-20',
                'notes' => 'Initial release covering personnel services and startup procurement.',
                'amount' => 1271350.40,
            ],
            2 => [
                'name' => "Tranche 2 {$dash} Mid-year Milestone",
                'percentage' => 30,
                'status' => 'released',
                'planned_release_date' => '2026-05-15',
                'released_at' => '2026-06-02',
                'notes' => 'Released after the first progress report and financial liquidation.',
                'amount' => 953512.80,
            ],
            3 => [
                'name' => "Tranche 3 {$dash} Field Validation",
                'percentage' => 20,
                'status' => 'due',
                'planned_release_date' => '2026-09-30',
                'released_at' => null,
                'notes' => 'Pending Program Leader approval of the field validation milestone.',
                'amount' => 635675.20,
            ],
            4 => [
                'name' => "Tranche 4 {$dash} Final Report & Closeout",
                'percentage' => 10,
                'status' => 'upcoming',
                'planned_release_date' => '2026-12-15',
                'released_at' => null,
                'notes' => 'Final release upon acceptance of the terminal report.',
                'amount' => 317837.60,
            ],
        ];

        $models = [];
        foreach ($tranches as $sequence => $tranche) {
            $models[$sequence] = ProjectTranche::updateOrCreate(
                ['project_id' => $project->id, 'sequence' => $sequence],
                array_merge($tranche, ['project_id' => $project->id])
            );
        }

        $trancheThree = $models[3];
        $trancheApproval = ApprovalRequest::updateOrCreate(
            [
                'project_id' => $project->id,
                'project_tranche_id' => $trancheThree->id,
                'type' => 'disbursement',
            ],
            [
                'title' => "Release Tranche 3 {$dash} Field Validation",
                'description' => 'Request for the third milestone release to fund field validation and data collection.',
                'amount' => 635675.20,
                'payload' => ['milestone' => 'Field Validation'],
                'requested_by' => $requester->id,
                'status' => 'pending',
                'reviewed_by' => null,
                'review_notes' => null,
                'reviewed_at' => null,
            ]
        );
        $trancheApproval->created_at = now()->subDays(8);
        $trancheApproval->updated_at = now()->subDays(8);
        $trancheApproval->saveQuietly();

        $travel = LibCategory::where('project_id', $project->id)->where('line_item_code', 'MOOE-01')->first();
        $professional = LibCategory::where('project_id', $project->id)->where('line_item_code', 'MOOE-04')->first();

        if ($travel && $professional) {
            $realignment = ApprovalRequest::updateOrCreate(
                ['project_id' => $project->id, 'type' => 'budget_realignment'],
                [
                    'project_tranche_id' => null,
                    'title' => "Realign {$peso}50,000 from Professional Services to Travel",
                    'description' => 'Move unused professional services allocation to cover additional field travel.',
                    'amount' => 50000.00,
                    'payload' => [
                        'from_lib_category_id' => $professional->id,
                        'to_lib_category_id' => $travel->id,
                        'amount' => 50000.00,
                    ],
                    'requested_by' => $requester->id,
                    'status' => 'pending',
                    'reviewed_by' => null,
                    'review_notes' => null,
                    'reviewed_at' => null,
                ]
            );
            $realignment->created_at = now()->subDays(8);
            $realignment->updated_at = now()->subDays(8);
            $realignment->saveQuietly();
        }
    }

    protected function seedUrbanReportsAndLiquidations(Project $project, User $requester): void
    {
        ProjectReport::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Progress report – Q3 2026'],
            [
                'type' => 'progress',
                'due_date' => '2026-10-30',
                'submitted_at' => null,
            ]
        );

        ProjectReport::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Progress report – Q4 2026'],
            [
                'type' => 'progress',
                'due_date' => '2026-12-15',
                'submitted_at' => null,
            ]
        );

        Liquidation::updateOrCreate(
            ['project_id' => $project->id, 'title' => 'Liquidation – Tranche 2'],
            [
                'project_tranche_id' => $project->tranches()->where('sequence', 2)->value('id'),
                'amount' => 512300,
                'due_date' => '2026-12-01',
                'submitted_at' => '2026-11-01',
                'status' => 'approved',
                'reviewed_at' => '2026-11-02 09:00:00',
            ]
        );
    }

    protected function seedUrbanApprovalDates(Project $project): void
    {
        $tranche = ApprovalRequest::where('project_id', $project->id)
            ->where('type', 'disbursement')
            ->where('status', 'pending')
            ->first();

        if ($tranche) {
            $tranche->created_at = now()->subDays(8);
            $tranche->updated_at = now()->subDays(8);
            $tranche->saveQuietly();
        }

        $realignment = ApprovalRequest::where('project_id', $project->id)
            ->where('type', 'budget_realignment')
            ->where('status', 'pending')
            ->first();

        if (! $realignment) {
            return;
        }

        // Keep the demo's control threshold at exactly 10% of the
        // ₱500,000 Professional Services line, and repair older titles that
        // were stored with question marks by a non-utf8mb4 connection.
        $payload = $realignment->payload ?? [];
        $payload['amount'] = 50000;

        $realignment->title = "Realign \u{20B1}50,000 from Professional Services to Travel";
        $realignment->amount = 50000;
        $realignment->payload = $payload;
        $realignment->created_at = now()->subDays(8);
        $realignment->updated_at = now()->subDays(8);
        $realignment->saveQuietly();
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3: float|int}> $categories
     */
    protected function seedCategories(Project $project, array $categories): void
    {
        foreach ($categories as $category) {
            LibCategory::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'line_item_code' => $category[0],
                ],
                [
                    'category_type' => $category[1],
                    'line_item_name' => $category[2],
                    'allocated_amount' => $category[3],
                ]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $expenses
     */
    protected function seedExpenses(Project $project, array $expenses, User $recorder, ?User $verifier): void
    {
        foreach ($expenses as $expense) {
            $category = LibCategory::where('project_id', $project->id)
                ->where('line_item_code', $expense['category'])
                ->first();

            if (! $category) {
                continue;
            }

            $status = $expense['status'] ?? 'verified';

            Expense::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'reference_no' => $expense['ref'],
                ],
                [
                    'lib_category_id' => $category->id,
                    'recorded_by' => $recorder->id,
                    'verified_by' => $status === 'verified' ? $verifier?->id : null,
                    'description' => $expense['description'],
                    'amount' => $expense['amount'],
                    'expense_date' => $expense['date'],
                    'attachment_path' => $expense['receipt']
                        ? 'receipts/' . $expense['ref'] . '.pdf'
                        : null,
                    'status' => $status,
                    'remarks' => $expense['receipt']
                        ? 'Digitized receipt on file.'
                        : 'Supporting document pending upload.',
                    'verified_at' => $status === 'verified' ? now() : null,
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function approval(Project $project, User $requester, array $data, int $daysWaiting): void
    {
        $approval = ApprovalRequest::updateOrCreate(
            [
                'project_id' => $project->id,
                'title' => $data['title'],
            ],
            array_merge([
                'type' => 'disbursement',
                'description' => null,
                'amount' => null,
                'payload' => null,
                'requested_by' => $requester->id,
                'status' => 'pending',
                'reviewed_by' => null,
                'review_notes' => null,
                'reviewed_at' => null,
            ], $data)
        );

        $approval->created_at = now()->subDays($daysWaiting);
        $approval->updated_at = now()->subDays($daysWaiting);
        $approval->saveQuietly();
    }
}
