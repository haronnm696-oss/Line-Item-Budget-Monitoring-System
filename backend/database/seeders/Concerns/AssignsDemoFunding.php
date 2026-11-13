<?php

namespace Database\Seeders\Concerns;

use App\Models\FundingAgency;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One place for the demo projects' funder, funder reference number and
 * Project Leader, so every seeder that creates these projects (each with its
 * own codes) ends up with the same assignments. All writes are idempotent.
 */
trait AssignsDemoFunding
{
    /** @var array<string, string> funder acronym => name, used only when the agency is missing */
    private array $demoFunders = [
        'MOST-BARMM' => 'Ministry of Science and Technology – BARMM',
        'DOST-PCAARRD' => 'DOST – Philippine Council for Agriculture, Aquatic and Natural Resources Research and Development',
        'CHED' => 'Commission on Higher Education',
    ];

    /** @var array<string, array{funder: string, reference: string, leader: string}> */
    private array $demoAssignments = [
        'urban' => [
            'funder' => 'MOST-BARMM',
            'reference' => 'MOST-BARMM-GIA-2026-0012',
            'leader' => 'project.leader@gmail.com',
        ],
        'goat' => [
            'funder' => 'DOST-PCAARRD',
            'reference' => 'PCAARRD-GIA-2026-0457',
            'leader' => 'project.leader2@gmail.com',
        ],
        'nutrition' => [
            'funder' => 'CHED',
            'reference' => 'CHED-DARETO-2026-0118',
            'leader' => 'project.leader4@gmail.com',
        ],
        'weaving' => [
            'funder' => 'MOST-BARMM',
            'reference' => 'MOST-BARMM-GIA-2025-0031',
            'leader' => 'project.leader3@gmail.com',
        ],
    ];

    protected function demoFunder(string $key): FundingAgency
    {
        $acronym = $this->demoAssignments[$key]['funder'];

        return FundingAgency::firstOrCreate(
            ['acronym' => $acronym],
            ['name' => $this->demoFunders[$acronym]]
        );
    }

    /**
     * Set the project's funder and reference number and make the demo
     * Project Leader its only project-leader assignment.
     */
    protected function assignDemoFunding(Project $project, string $key): void
    {
        $assignment = $this->demoAssignments[$key];

        $project->forceFill([
            'funding_agency_id' => $this->demoFunder($key)->id,
            'funder_reference' => $assignment['reference'],
        ])->save();

        $leader = User::where('email', $assignment['leader'])->first();
        $role = Role::where('slug', 'project-leader')->first();
        if (! $leader || ! $role) {
            return;
        }

        DB::table('project_user')
            ->where('project_id', $project->id)
            ->where('role_id', $role->id)
            ->where('user_id', '<>', $leader->id)
            ->delete();

        DB::table('project_user')->updateOrInsert(
            ['project_id' => $project->id, 'user_id' => $leader->id, 'role_id' => $role->id],
            ['assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /**
     * Dummy payee for a seeded expense, chosen from the line item name
     * (line codes differ between seeders, names do not).
     */
    protected function demoPayee(Project $project, string $lineItemName): ?string
    {
        $name = strtolower($lineItemName);
        $person = fn (string $email) => User::where('email', $email)->value('name');

        if (str_contains($name, 'project leader')) {
            return $project->usersWithRole('project-leader')->value('name');
        }
        if (str_contains($name, 'research assistant')) {
            return $person('assistant@gmail.com');
        }
        if (str_contains($name, 'program leader')) {
            return $person('program.leader@gmail.com');
        }
        if (str_contains($name, 'researcher')) {
            return $person('researcher@gmail.com');
        }

        $vendors = [
            'travel' => 'Lanao Express Transport Services',
            'supplies' => 'Marawi Techno Supply',
            'communication' => 'Lanao Telecom Load Center',
            'professional' => 'Contracted enumerators (job order)',
            'cloud' => 'Mindanao Cloud Hosting Services',
            'utilities' => 'MSU-Main Campus Utilities Office',
        ];
        foreach ($vendors as $keyword => $vendor) {
            if (str_contains($name, $keyword)) {
                return $vendor;
            }
        }

        return null;
    }
}
