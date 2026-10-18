<?php

namespace Database\Seeders;

use App\Models\LibCategory;
use App\Models\Project;
use Illuminate\Database\Seeder;

class LibCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::where('code', 'MOST-RD-2026-001')->first();
        if (! $project) {
            return;
        }

        $categories = [
            // --- I. Personnel Services (PS) ---
            ['category_type' => 'PS', 'line_item_name' => 'Project Leader Honoraria (Prof. Mudzna M. Asakil)', 'line_item_code' => 'PS-01', 'allocated_amount' => 400000.00],
            ['category_type' => 'PS', 'line_item_name' => 'Research Assistant Honoraria (2 personnel)', 'line_item_code' => 'PS-02', 'allocated_amount' => 360000.00],
            ['category_type' => 'PS', 'line_item_name' => 'Researcher Honoraria', 'line_item_code' => 'PS-03', 'allocated_amount' => 240000.00],
            ['category_type' => 'PS', 'line_item_name' => 'Program Leader Indirect Honoraria', 'line_item_code' => 'PS-04', 'allocated_amount' => 200000.00],

            // --- II. Maintenance and Other Operating Expenses (MOOE) ---
            ['category_type' => 'MOOE', 'line_item_name' => 'Traveling Expenses', 'line_item_code' => 'MOOE-01', 'allocated_amount' => 350000.00],
            ['category_type' => 'MOOE', 'line_item_name' => 'Supplies & Materials (IoT Sensors, Laptops)', 'line_item_code' => 'MOOE-02', 'allocated_amount' => 600000.00],
            ['category_type' => 'MOOE', 'line_item_name' => 'Communication Expenses', 'line_item_code' => 'MOOE-03', 'allocated_amount' => 120000.00],
            ['category_type' => 'MOOE', 'line_item_name' => 'Professional Services (Programmers/Enumerators)', 'line_item_code' => 'MOOE-04', 'allocated_amount' => 500000.00],
            ['category_type' => 'MOOE', 'line_item_name' => 'Cloud/Server Rent', 'line_item_code' => 'MOOE-05', 'allocated_amount' => 200000.00],
            ['category_type' => 'MOOE', 'line_item_name' => 'Utilities', 'line_item_code' => 'MOOE-06', 'allocated_amount' => 208376.00],
        ];

        foreach ($categories as $c) {
            LibCategory::updateOrCreate(
                ['project_id' => $project->id, 'line_item_code' => $c['line_item_code']],
                array_merge($c, ['project_id' => $project->id])
            );
        }
    }
}
