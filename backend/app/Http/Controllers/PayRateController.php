<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * Pay rates on a project (project_user.monthly_rate / allowance), read by
 * payroll computation. Finance/Admin of the project or the Program Leader
 * sets them; Research Assistants (who prepare payroll) may read them.
 * Researchers and Project Leaders get 403. Empty = "Not set" (null).
 * Each saved change writes one "pay_rate_changed" audit entry; a save that
 * changes nothing writes none. Changing a rate never touches submitted or
 * verified batches; draft and returned batches need "Recompute".
 */
class PayRateController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $actor = $request->user();
        abort_unless(
            $actor->isProgramLeader() || $actor->hasRoleOn($project, ['finance-admin', 'research-assistant']),
            403,
            'Only Finance/Admin staff, Research Assistants of this project or the Program Leader can see pay rates.'
        );

        $rows = ProjectUser::query()
            ->where('project_id', $project->id)
            ->with(['user', 'role'])
            ->orderBy('id')
            ->get()
            ->filter(fn (ProjectUser $member) => $member->user !== null)
            ->map(fn (ProjectUser $member) => $this->row($member))
            ->values();

        return response()->json(['members' => $rows]);
    }

    public function update(Request $request, Project $project, User $user)
    {
        $actor = $request->user();
        abort_unless(
            $actor->isProgramLeader() || $actor->hasRoleOn($project, 'finance-admin'),
            403,
            'Only Finance/Admin staff of this project or the Program Leader can set pay rates.'
        );

        $money = ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];
        $data = $request->validate([
            'monthly_rate' => array_merge(['present'], $money),
            'allowance' => $money,
        ], [
            'monthly_rate.min' => 'The monthly rate cannot be negative.',
            'monthly_rate.numeric' => 'Enter the monthly rate as a number.',
            'monthly_rate.regex' => 'Use at most two decimals for the monthly rate.',
            'allowance.min' => 'The allowance cannot be negative.',
            'allowance.numeric' => 'Enter the allowance as a number.',
            'allowance.regex' => 'Use at most two decimals for the allowance.',
        ]);

        $member = ProjectUser::query()->where('project_id', $project->id)->where('user_id', $user->id)->first();
        abort_unless($member, 404, 'This person is not assigned to this project.');

        $member = AuditLogger::transaction(function () use ($member, $data, $actor, $project) {
            $locked = ProjectUser::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $old = ['project_id' => $project->id, 'monthly_rate' => $locked->monthly_rate, 'allowance' => $locked->allowance];
            $next = [
                'monthly_rate' => $data['monthly_rate'],
                'allowance' => $data['monthly_rate'] === null ? null : ($data['allowance'] ?? 0),
            ];
            $same = fn ($a, $b) => ($a === null && $b === null) || ($a !== null && $b !== null && round((float) $a, 2) === round((float) $b, 2));
            if ($same($old['monthly_rate'], $next['monthly_rate']) && $same($old['allowance'], $next['allowance'])) {
                return $locked; // nothing changed: no audit entry
            }
            $locked->update($next);
            $fresh = $locked->fresh();
            AuditLogger::write('pay_rate_changed', $fresh, $old, [
                'project_id' => $project->id, 'monthly_rate' => $fresh->monthly_rate, 'allowance' => $fresh->allowance,
            ], $actor);

            return $fresh;
        });

        return response()->json(['message' => 'Pay rate saved.', 'member' => $this->row($member->load(['user', 'role']))]);
    }

    private function row(ProjectUser $member): array
    {
        return [
            'user_id' => $member->user_id,
            'name' => $member->user?->name,
            'role' => $member->role?->name,
            'role_slug' => $member->role?->slug,
            'is_active' => (bool) $member->user?->is_active,
            'monthly_rate' => $member->monthly_rate !== null ? (float) $member->monthly_rate : null,
            'allowance' => $member->allowance !== null ? (float) $member->allowance : null,
        ];
    }
}
