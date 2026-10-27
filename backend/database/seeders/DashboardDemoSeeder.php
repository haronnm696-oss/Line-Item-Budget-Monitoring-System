<?php

namespace Database\Seeders;

use App\Models\ApprovalRequest;
use App\Models\Expense;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectTranche;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data for the five advanced Program Leader dashboard features:
 * fund-release tranches, burn-rate signals, quick approvals, receipt
 * compliance, and report exports.
 */
class DashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::where('code', 'MOST-RD-2026-001')->first();
        if (! $project) {
            return;
        }

        $project->update([
            'total_approved_budget' => 3178376.00,
            'implementing_agency_counterpart' => 1178376.00,
            'funding_agency_amount' => 2000000.00,
        ]);

        $this->assignTeam($project);
        $tranches = $this->seedTranches($project);
        $this->seedExpenses($project);
        $this->seedApprovals($project, $tranches);
    }

    protected function assignTeam(Project $project): void
    {
        $team = [
            'program.leader@gmail.com' => 'program-leader',
            'project.leader@gmail.com' => 'project-leader',
            'assistant@gmail.com' => 'research-assistant',
            'researcher@gmail.com' => 'researcher',
            'finance@gmail.com' => 'finance-admin',
        ];

        foreach ($team as $email => $slug) {
            $user = User::where('email', $email)->first();
            $role = Role::where('slug', $slug)->first();

            if (! $user || ! $role) {
                continue;
            }

            ProjectUser::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                ],
                ['assigned_at' => now()]
            );
        }
    }

    /**
     * @return array<int, ProjectTranche>
     */
    protected function seedTranches(Project $project): array
    {
        $budget = (float) $project->total_approved_budget;
        $tranches = [
            [
                'sequence' => 1,
                'name' => 'Tranche 1 — Project Mobilization',
                'percentage' => 40,
                'status' => 'released',
                'planned_release_date' => '2026-01-15',
                'released_at' => '2026-01-20',
                'notes' => 'Initial release covering personnel services and startup procurement.',
            ],
            [
                'sequence' => 2,
                'name' => 'Tranche 2 — Mid-year Milestone',
                'percentage' => 30,
                'status' => 'released',
                'planned_release_date' => '2026-05-15',
                'released_at' => '2026-06-02',
                'notes' => 'Released after the first progress report and financial liquidation.',
            ],
            [
                'sequence' => 3,
                'name' => 'Tranche 3 — Field Validation',
                'percentage' => 20,
                'status' => 'due',
                'planned_release_date' => '2026-09-30',
                'released_at' => null,
                'notes' => 'Pending Program Leader approval of the field validation milestone.',
            ],
            [
                'sequence' => 4,
                'name' => 'Tranche 4 — Final Report & Closeout',
                'percentage' => 10,
                'status' => 'upcoming',
                'planned_release_date' => '2026-12-15',
                'released_at' => null,
                'notes' => 'Final release upon acceptance of the terminal report.',
            ],
        ];

        $models = [];
        foreach ($tranches as $tranche) {
            $models[] = ProjectTranche::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'sequence' => $tranche['sequence'],
                ],
                array_merge($tranche, [
                    'project_id' => $project->id,
                    'amount' => round($budget * ($tranche['percentage'] / 100), 2),
                ])
            );
        }

        return $models;
    }

    protected function seedExpenses(Project $project): void
    {
        $recorder = User::where('email', 'assistant@gmail.com')->first()
            ?? User::query()->orderBy('id')->first();
        $verifier = User::where('email', 'finance@gmail.com')->first()
            ?? User::query()->orderBy('id')->first();

        if (! $recorder) {
            return;
        }

        // Amounts are chosen to produce a realistic ~54% utilization and a
        // 70% receipt compliance score for the demo dashboard.
        $expenses = [
            ['PS-01', 260000.00, '2026-02-15', 'verified', true, 'Project Leader honoraria, Q1-Q3'],
            ['PS-02', 240000.00, '2026-03-15', 'verified', true, 'Research Assistant honoraria'],
            ['PS-03', 160000.00, '2026-04-15', 'verified', false, 'Researcher honoraria'],
            ['PS-04', 120000.00, '2026-09-15', 'pending', true, 'Program Leader indirect honoraria'],
            ['MOOE-01', 180000.00, '2026-05-20', 'verified', true, 'Field travel and validation activities'],
            ['MOOE-02', 320000.00, '2026-06-10', 'verified', true, 'IoT sensors, laptops, and supplies'],
            ['MOOE-03', 65000.00, '2026-07-05', 'verified', false, 'Connectivity and communication expenses'],
            ['MOOE-04', 250000.00, '2026-08-12', 'pending', false, 'Programmers and enumerators'],
            ['MOOE-05', 90000.00, '2026-08-30', 'verified', true, 'Cloud/server rent'],
            ['MOOE-06', 45000.00, '2026-09-20', 'verified', true, 'Utilities and office overhead'],
        ];

        foreach ($expenses as [$code, $amount, $date, $status, $hasReceipt, $description]) {
            $category = LibCategory::where('project_id', $project->id)
                ->where('line_item_code', $code)
                ->first();

            if (! $category) {
                continue;
            }

            Expense::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'reference_no' => 'DEMO-' . $code,
                ],
                [
                    'lib_category_id' => $category->id,
                    'recorded_by' => $recorder->id,
                    'verified_by' => $status === 'verified' ? $verifier?->id : null,
                    'description' => $description,
                    'amount' => $amount,
                    'expense_date' => $date,
                    'attachment_path' => $hasReceipt ? 'receipts/DEMO-' . $code . '.pdf' : null,
                    'status' => $status,
                    'remarks' => $hasReceipt ? 'Digitized receipt on file.' : 'Supporting document pending upload.',
                    'verified_at' => $status === 'verified' ? now() : null,
                ]
            );
        }
    }

    /**
     * @param array<int, ProjectTranche> $tranches
     */
    protected function seedApprovals(Project $project, array $tranches): void
    {
        $requester = User::where('email', 'project.leader@gmail.com')->first()
            ?? User::where('email', 'program.leader@gmail.com')->first();

        $trancheThree = collect($tranches)->firstWhere('sequence', 3);
        $travel = LibCategory::where('project_id', $project->id)->where('line_item_code', 'MOOE-01')->first();
        $professional = LibCategory::where('project_id', $project->id)->where('line_item_code', 'MOOE-04')->first();

        ApprovalRequest::updateOrCreate(
            [
                'project_id' => $project->id,
                'title' => 'Release Tranche 3 — Field Validation',
            ],
            [
                'project_tranche_id' => $trancheThree?->id,
                'type' => 'disbursement',
                'description' => 'Request for the third milestone release to fund field validation and data collection.',
                'amount' => 635675.20,
                'payload' => ['milestone' => 'Field Validation'],
                'requested_by' => $requester?->id,
                'status' => 'pending',
                'reviewed_by' => null,
                'review_notes' => null,
                'reviewed_at' => null,
            ]
        );

        if ($travel && $professional) {
            ApprovalRequest::updateOrCreate(
                [
                    'project_id' => $project->id,
                    'title' => 'Realign ₱40,000 from Professional Services to Travel',
                ],
                [
                    'project_tranche_id' => null,
                    'type' => 'budget_realignment',
                    'description' => 'Move unused professional services allocation to cover additional field travel.',
                    'amount' => 40000.00,
                    'payload' => [
                        'from_lib_category_id' => $professional->id,
                        'to_lib_category_id' => $travel->id,
                        'amount' => 40000.00,
                    ],
                    'requested_by' => $requester?->id,
                    'status' => 'pending',
                    'reviewed_by' => null,
                    'review_notes' => null,
                    'reviewed_at' => null,
                ]
            );
        }
    }
}
