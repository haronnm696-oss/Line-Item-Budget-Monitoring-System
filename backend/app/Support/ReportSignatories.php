<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;

/**
 * Names for the signature blocks of the printed reports (Expense Ledger,
 * Utilization, Payroll register, Monthly summary, Executive report, Audit
 * trail). A missing name prints as a blank line with the role under it.
 */
class ReportSignatories
{
    /** "Certified correct": the project's first active Finance/Admin (by assignment), or null. */
    public static function financeAdmin(Project $project): ?string
    {
        return $project->usersWithRole('finance-admin')
            ->where('users.is_active', true)
            ->orderBy('project_user.id')
            ->first()?->name;
    }

    /** "Approved by" on program-wide reports: the first active Program Leader, or null. */
    public static function programLeader(): ?string
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('primaryRole', fn ($q) => $q->where('slug', 'program-leader'))
            ->orderBy('id')
            ->value('name');
    }
}
