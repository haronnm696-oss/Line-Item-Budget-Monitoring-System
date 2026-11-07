<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    /**
     * One account per person, one primary role per account.
     *
     * Demo password for every account is `password123` so each role can be
     * signed into with email + password.
     */
    public function run(): void
    {
        $accounts = [
            [
                'first_name' => 'Rahana',
                'last_name' => 'Bayabao',
                'name' => 'Dr. Rahana Bayabao',
                'email' => 'program.leader@gmail.com',
                'employee_id' => 'MSU-05561',
                'position' => 'Program Leader, OVCRE',
                'college' => 'College of Computing and Information Sciences',
                'department' => 'Computer Science',
                'slug' => 'program-leader',
            ],
            [
                'first_name' => 'Aminah',
                'last_name' => 'Macarambon',
                'name' => 'Dr. Aminah Macarambon',
                'email' => 'project.leader@gmail.com',
                'employee_id' => 'MSU-04182',
                'position' => 'Project Leader, Urban Mobility',
                'college' => 'College of Social Sciences and Humanities',
                'department' => 'Research',
                'slug' => 'project-leader',
            ],
            [
                'first_name' => 'Sittie Ayesah',
                'last_name' => 'Alonto',
                'name' => 'Dr. Sittie Ayesah Alonto',
                'email' => 'project.leader2@gmail.com',
                'employee_id' => 'MSU-04183',
                'position' => 'Project Leader, Halal Goat Value Chain',
                'college' => 'College of Business and Finance',
                'department' => 'Finance',
                'slug' => 'project-leader',
            ],
            [
                'first_name' => 'Jamal',
                'last_name' => 'Macaraya',
                'name' => 'Engr. Jamal Macaraya',
                'email' => 'project.leader3@gmail.com',
                'employee_id' => 'MSU-04184',
                'position' => 'Project Leader, Weaving Heritage Archive',
                'college' => 'College of Computing and Information Sciences',
                'department' => 'Information Technology',
                'slug' => 'project-leader',
            ],
            [
                'first_name' => 'Norhaya',
                'last_name' => 'Dimakaling',
                'name' => 'Dr. Norhaya Dimakaling',
                'email' => 'project.leader4@gmail.com',
                'employee_id' => 'MSU-04185',
                'position' => 'Project Leader, Community Nutrition',
                'college' => 'College of Social Sciences and Humanities',
                'department' => 'Education',
                'slug' => 'project-leader',
            ],
            [
                'first_name' => 'Norjannah',
                'last_name' => 'Haron',
                'name' => 'Norjannah Haron',
                'email' => 'assistant@gmail.com',
                'employee_id' => 'MSU-09214',
                'position' => 'Research Assistant',
                'college' => 'College of Business and Finance',
                'department' => 'Accounting',
                'slug' => 'research-assistant',
            ],
            [
                'first_name' => 'Faisal',
                'last_name' => 'Sarip',
                'name' => 'Faisal Sarip',
                'email' => 'finance@gmail.com',
                'employee_id' => 'MSU-03327',
                'position' => 'Finance/Admin Officer, OVCRE',
                'college' => 'College of Business and Finance',
                'department' => 'Finance',
                'slug' => 'finance-admin',
            ],
            [
                'first_name' => 'Sittie',
                'last_name' => 'Dimaporo',
                'name' => 'Sittie Dimaporo',
                'email' => 'researcher@gmail.com',
                'employee_id' => 'MSU-11048',
                'position' => 'Researcher',
                'college' => 'College of Computing and Information Sciences',
                'department' => 'Data Science',
                'slug' => 'researcher',
            ],
        ];

        foreach ($accounts as $account) {
            $role = Role::where('slug', $account['slug'])->first();
            if (! $role) {
                continue;
            }

            User::updateOrCreate(
                ['email' => strtolower($account['email'])],
                [
                    'first_name' => $account['first_name'],
                    'last_name' => $account['last_name'],
                    'name' => $account['name'],
                    'employee_id' => $account['employee_id'],
                    'password' => Hash::make('password123'),
                    'position' => $account['position'],
                    'college' => $account['college'],
                    'department' => $account['department'],
                    'role_id' => $role->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
