<?php

namespace Database\Seeders;

use App\Models\FundingAgency;
use App\Models\LibCategory;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\Role;
use App\Models\User;
use App\Support\LineItemComputation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One demo project built from a real R&D Form B (Project Line-Item Budget)
 * funded by MOST-BARMM, 12 months:
 *
 *                     Implementing agency        Funder
 *   PS                       1,058,376.00     351,600.00
 *   MOOE                       120,000.00   1,648,400.00
 *   Grand total              1,178,376.00   2,000,000.00   = 3,178,376.00
 *
 * The Project Leader is a placeholder account (no real person's name).
 * Safe to rerun: the project's line items are rebuilt only while nothing is
 * charged to them.
 */
class FormBDemoSeeder extends Seeder
{
    public const CODE = 'FORMB-2026-001';
    public const TITLE = 'Enhancing Urban Mobility: Integrated Tracking and Commuter Management System';

    /**
     * [type, cost type, group, sub-group, name, computation|null, counterpart, funder]
     * computation = [quantity, unit, rate, period|null, periods]. As on Form B,
     * a computation gives the funder amount; the counterpart is typed on its
     * own and added to it. The PS rows are the official Form B rows.
     */
    public const LINE_ITEMS = [
        // ---- PS, Direct (official rows)
        ['PS', 'Direct', 'Honoraria', null, 'Project Leader', [1, 'Project Leader', 8800, 'month', 12], 254754.00, null],
        ['PS', 'Direct', 'Honoraria', null, 'Project Staff Level 3', [1, 'Project Staff Level 3', 7500, 'month', 12], 509508.00, null],
        ['PS', 'Direct', 'Honoraria', null, 'Project Staff Level 2', [2, 'Project Staff Level 2', 6000, 'month', 12], 294114.00, null],
        // ---- PS, Indirect (official row)
        ['PS', 'Indirect', 'Honoraria', null, 'Project Support Staff Level 2', [2, 'Project Support Staff Level 2', 1500, 'quarter', 4], 0, null],
        // ---- MOOE, Direct
        ['MOOE', 'Direct', 'Traveling Expenses', null, 'Local travel', null, 0, 150000.00],
        ['MOOE', 'Direct', 'Training Expenses', null, 'Stakeholder and commuter workshops', null, 0, 120000.00],
        ['MOOE', 'Direct', 'Supplies and Materials Expenses', null, 'Office supplies', null, 0, 60000.00],
        ['MOOE', 'Direct', 'Supplies and Materials Expenses', 'Semi-Expendable Machinery and Equipment', 'IoT tracking devices', [1, 'lot', 56070, null, 5], 0, null],
        ['MOOE', 'Direct', 'Supplies and Materials Expenses', 'Semi-Expendable Machinery and Equipment', 'Laptop computers', [1, 'unit', 33130, null, 5], 0, null],
        ['MOOE', 'Direct', 'Supplies and Materials Expenses', 'Semi-Expendable Machinery and Equipment', 'Tablets', [1, 'unit', 16950, null, 3], 0, null],
        ['MOOE', 'Direct', 'Communication Expenses', null, 'Internet and mobile data', null, 0, 48000.00],
        ['MOOE', 'Direct', 'Professional Services', null, 'Software developers', null, 0, 600000.00],
        ['MOOE', 'Direct', 'Printing and Binding Expenses', null, 'Reports and dissemination materials', null, 0, 30000.00],
        ['MOOE', 'Direct', 'Other Maintenance and Operating Expenses', null, 'Cloud server subscription', null, 0, 143550.00],
        // ---- MOOE, Indirect
        ['MOOE', 'Indirect', 'Utilities', null, 'Electricity and water', null, 120000.00, 0],
    ];

    public function run(): void
    {
        $funder = FundingAgency::firstOrCreate(
            ['acronym' => 'MOST-BARMM'],
            ['name' => 'Ministry of Science and Technology - BARMM']
        );
        $leader = $this->placeholderLeader();

        $project = Project::updateOrCreate(['code' => self::CODE], [
            'title' => self::TITLE,
            'description' => 'Demo project from an R&D Form B (Project Line-Item Budget).',
            'funding_agency_id' => $funder->id,
            'total_approved_budget' => 3178376.00,
            // Legacy columns, kept equal to the line-item sums.
            'implementing_agency_counterpart' => 1178376.00,
            'funding_agency_amount' => 2000000.00,
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'status' => 'ongoing',
        ]);

        if ($leader) {
            ProjectUser::firstOrCreate(
                ['project_id' => $project->id, 'user_id' => $leader->id],
                ['role_id' => $leader->role_id, 'assigned_at' => now()]
            );
        }

        $inUse = $project->expenses()->withTrashed()->exists() || $project->purchaseRequests()->exists() || $project->payrolls()->exists();
        if ($inUse) {
            return;
        }
        $project->libCategories()->delete();
        foreach (self::LINE_ITEMS as $i => $row) {
            LibCategory::create(self::attributes($row) + [
                'project_id' => $project->id,
                'line_item_code' => sprintf('%s-%02d', $row[0], $i + 1),
            ]);
        }
    }

    /** One LINE_ITEMS row as lib_categories attributes. */
    public static function attributes(array $row): array
    {
        [$type, $cost, $group, $subgroup, $name, $computation, $counterpart, $funder] = $row;
        $fields = [
            'category_type' => $type,
            'cost_type' => $cost,
            'expense_group' => $group,
            'expense_subgroup' => $subgroup,
            'line_item_name' => $name,
            'implementing_agency_amount' => $counterpart,
        ];
        if ($computation) {
            [$quantity, $unit, $rate, $period, $periods] = $computation;
            $fields += ['quantity' => $quantity, 'unit_description' => $unit, 'rate' => $rate, 'rate_period' => $period, 'periods' => $periods];
            $funder = LineItemComputation::amount($fields);
        }

        return $fields + ['funder_amount' => $funder];
    }

    private function placeholderLeader(): ?User
    {
        $role = Role::where('slug', 'project-leader')->first();
        if (! $role) {
            return null;
        }

        return User::firstOrCreate(['email' => 'formb.leader@example.test'], [
            'first_name' => 'Demo',
            'last_name' => 'Project Leader',
            'name' => 'Demo Project Leader',
            'password' => Hash::make(Str::random(40)),
            'position' => 'Project Leader',
            'role_id' => $role->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
