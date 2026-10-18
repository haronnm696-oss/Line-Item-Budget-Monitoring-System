<?php

namespace Database\Seeders;

use App\Models\Project;
use Illuminate\Database\Seeder;

class ProjectsSeeder extends Seeder
{
    public function run(): void
    {
        $projects = [
            [
                'code' => 'MOST-RD-2026-001',
                'title' => 'Enhancing Urban Mobility',
                'description' => 'Line-Item Budget (Form B) project for enhancing urban mobility in Marawi City, funded by MOST-BARMM with MSU-Marawi counterpart.',
                'funding_agency_id' => null,
                'total_approved_budget' => 3178376.00,
                'implementing_agency_counterpart' => 1178376.00,
                'funding_agency_amount' => 2000000.00,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'ongoing',
            ],
            [
                'code' => 'RP-2026-009',
                'title' => 'Halal Goat Value Chain Development, Lanao del Sur',
                'description' => 'DOST-PCAARRD funded project on halal goat value chain development.',
                'funding_agency_id' => null,
                'total_approved_budget' => 1870000.00,
                'implementing_agency_counterpart' => 0.00,
                'funding_agency_amount' => 1870000.00,
                'start_date' => '2026-03-01',
                'end_date' => '2027-02-28',
                'status' => 'ongoing',
            ],
            [
                'code' => 'RP-2025-031',
                'title' => 'Meranaw Weaving Heritage Digital Archive',
                'description' => 'CHED funded project on digitizing Meranaw weaving heritage.',
                'funding_agency_id' => null,
                'total_approved_budget' => 940000.00,
                'implementing_agency_counterpart' => 0.00,
                'funding_agency_amount' => 940000.00,
                'start_date' => '2025-06-01',
                'end_date' => '2026-05-31',
                'status' => 'completed',
            ],
            [
                'code' => 'RP-2026-021',
                'title' => 'Community Nutrition Surveillance, Marawi Resettlement Sites',
                'description' => 'DOST-PCHRD funded community nutrition surveillance project.',
                'funding_agency_id' => null,
                'total_approved_budget' => 1210000.00,
                'implementing_agency_counterpart' => 0.00,
                'funding_agency_amount' => 1210000.00,
                'start_date' => '2026-08-01',
                'end_date' => '2027-07-31',
                'status' => 'planning',
            ],
        ];

        foreach ($projects as $p) {
            Project::updateOrCreate(
                ['code' => $p['code']],
                $p
            );
        }
    }
}
