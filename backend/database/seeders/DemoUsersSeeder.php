<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    /**
     * Demo accounts per primary role so each login lands
     * directly on its own dashboard.
     *
     * Demo accounts (@msumain.edu.ph) use the default demo password: Password123!
     * Existing Gmail accounts (@gmail.com) keep password: password123
     */
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Dr. Rahana Bayabao',
                'email' => 'program.leader@gmail.com',
                'employee_id' => 'MSU-05561',
                'position' => 'Associate Professor, CICS',
                'slug' => 'program-leader',
                'password' => 'password123',
            ],
            [
                'name' => 'Dr. Aminah Macarambon',
                'email' => 'project.leader@gmail.com',
                'employee_id' => 'MSU-04182',
                'position' => 'Professor VI, CNSM',
                'slug' => 'project-leader',
                'password' => 'password123',
            ],
            [
                'name' => 'Sittie Dimaporo',
                'email' => 'researcher@gmail.com',
                'employee_id' => 'MSU-11048',
                'position' => 'Research Aide I',
                'slug' => 'researcher',
                'password' => 'password123',
            ],
            [
                'name' => 'Norjannah Haron',
                'email' => 'assistant@gmail.com',
                'employee_id' => 'MSU-09214',
                'position' => 'Research Assistant',
                'slug' => 'research-assistant',
                'password' => 'password123',
            ],
            [
                'name' => 'Faisal Sarip',
                'email' => 'finance@gmail.com',
                'employee_id' => 'MSU-03327',
                'position' => 'Admin Officer IV, OVCRE',
                'slug' => 'finance-admin',
                'password' => 'password123',
            ],
            // Demo / sample accounts for testing and demonstration
            [
                'name' => 'Ilham Gamil',
                'email' => 'ilham.gaml@msumain.edu.ph',
                'employee_id' => 'MSU-DEMO01',
                'position' => 'Program Leader (Demo)',
                'slug' => 'program-leader',
                'password' => 'Password123!',
            ],
            [
                'name' => 'Project Leader Demo',
                'email' => 'project.leader@msumain.edu.ph',
                'employee_id' => 'MSU-DEMO02',
                'position' => 'Project Leader (Demo)',
                'slug' => 'project-leader',
                'password' => 'Password123!',
            ],
            [
                'name' => 'RA Sample',
                'email' => 'ra.sample@msumain.edu.ph',
                'employee_id' => 'MSU-DEMO03',
                'position' => 'Research Assistant (Demo)',
                'slug' => 'research-assistant',
                'password' => 'Password123!',
            ],
            [
                'name' => 'Researcher Sample',
                'email' => 'researcher.sample@msumain.edu.ph',
                'employee_id' => 'MSU-DEMO04',
                'position' => 'Researcher (Demo)',
                'slug' => 'researcher',
                'password' => 'Password123!',
            ],
        ];

        foreach ($accounts as $a) {
            $role = Role::where('slug', $a['slug'])->first();
            if (! $role) continue;

            User::updateOrCreate(
                ['email' => strtolower($a['email'])],
                [
                    'name' => $a['name'],
                    'employee_id' => $a['employee_id'],
                    'password' => Hash::make($a['password'] ?? 'password123'),
                    'position' => $a['position'],
                    'role_id' => $role->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
