<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * One rule, used everywhere a Project Leader is set (New project, Edit
 * project details, Assign to project, registration review, account role
 * change, "Fix" role, reactivating a project, seeders):
 *
 *   A Project Leader leads only ONE active project at a time.
 *
 * Active = planning or ongoing (ProjectFinancialService::derivedStatus):
 * the end date is not yet past. Completed projects do not count. Existing
 * data that breaks the rule is never changed automatically; conflicts()
 * lists it for the Program Leader to fix.
 */
class ProjectLeaderRule
{
    public const SLUG = 'project-leader';

    /** Active projects only (end date today or later, or not set). */
    public static function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today));
    }

    public static function isActive(Project $project): bool
    {
        return app(ProjectFinancialService::class)->derivedStatus($project) !== 'completed';
    }

    /**
     * The active projects $user leads, other than $except, most recently
     * started first.
     *
     * @return Collection<int, Project>
     */
    public static function activeLed(User $user, ?Project $except = null): Collection
    {
        return self::scopeActive(Project::query())
            ->whereHas('projectUsers', fn ($q) => $q->where('user_id', $user->id)
                ->whereHas('role', fn ($r) => $r->where('slug', self::SLUG)))
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get(['id', 'code', 'title', 'start_date', 'end_date']);
    }

    public static function message(User $user, Project $other): string
    {
        return "{$user->name} already leads {$other->code}. A Project Leader leads one active project at a time.";
    }

    /**
     * The active project that stops $user from leading $project (null when
     * allowed: $project is completed, or they lead no other active project).
     * Seeders use this to skip with a warning instead of failing.
     */
    public static function blockingProject(User $user, Project $project): ?Project
    {
        return self::isActive($project) ? self::activeLed($user, $project)->first() : null;
    }

    /**
     * Refuse making $user the leader of $project when the project is active
     * and they already lead another active project. A completed project may
     * always get its leader. With $field: a 422 validation error on that
     * form field; with null: a plain 422 whose message is the refusal (the
     * Users and roles dialogs show `message`).
     */
    public static function ensureCanLead(User $user, Project $project, ?string $field = 'project_leader_id'): void
    {
        $other = self::blockingProject($user, $project);
        if ($other) {
            self::refuse($user, $other, $field);
        }
    }

    /** @return never */
    public static function refuse(User $user, Project $other, ?string $field): void
    {
        if ($field === null) {
            abort(422, self::message($user, $other));
        }
        throw ValidationException::withMessages([$field => [self::message($user, $other)]]);
    }

    /**
     * Reactivating: $project was completed and its new dates make it active
     * again. Refused when its leader now leads another active project.
     */
    public static function ensureCanReactivate(Project $project, bool $wasActive, string $field = 'end_date'): void
    {
        if ($wasActive || ! self::isActive($project)) {
            return;
        }
        foreach (self::leadersOf($project) as $leader) {
            self::ensureCanLead($leader, $project, $field);
        }
    }

    /** @return Collection<int, User> */
    public static function leadersOf(Project $project): Collection
    {
        return User::query()
            ->whereHas('projectUsers', fn ($q) => $q->where('project_id', $project->id)
                ->whereHas('role', fn ($r) => $r->where('slug', self::SLUG)))
            ->orderBy('id')->get();
    }

    /**
     * Project Leaders who lead more than one active project (older data),
     * with those projects, most recently started first. Nothing is changed.
     *
     * @return array<int, array{user: array{id: int, name: string}, count: int, message: string, projects: array<int, array{id: int, code: string, title: string, start_date: ?string}>}>
     */
    public static function conflicts(): array
    {
        $rows = ProjectUser::query()
            ->whereHas('role', fn ($r) => $r->where('slug', self::SLUG))
            ->whereHas('project', fn ($q) => self::scopeActive($q))
            ->with(['user:id,name', 'project:id,code,title,start_date,end_date'])
            ->get()
            ->filter(fn (ProjectUser $row) => $row->user && $row->project)
            ->unique(fn (ProjectUser $row) => $row->user_id . ':' . $row->project_id)
            ->groupBy('user_id')
            ->filter(fn (Collection $group) => $group->count() > 1);

        return $rows->map(function (Collection $group) {
            $projects = $group->map->project
                ->sortByDesc(fn (Project $project) => [$project->start_date?->toDateString(), $project->id])
                ->values();
            $count = $projects->count();

            return [
                'user' => ['id' => (int) $group->first()->user->id, 'name' => $group->first()->user->name],
                'count' => $count,
                'message' => "Leads {$count} active projects; reassign all but one",
                'projects' => $projects->map(fn (Project $project) => [
                    'id' => (int) $project->id,
                    'code' => $project->code,
                    'title' => $project->title,
                    'start_date' => $project->start_date?->toDateString(),
                ])->all(),
            ];
        })->sortBy(fn (array $row) => $row['user']['name'])->values()->all();
    }

    /**
     * Per leader id, the active projects they lead (for the Project leader
     * dropdown: free people, and the others with the project they lead).
     *
     * @return array<int, array<int, array{id: int, code: string, title: string}>>
     */
    public static function activeLeadershipsByUser(): array
    {
        return ProjectUser::query()
            ->whereHas('role', fn ($r) => $r->where('slug', self::SLUG))
            ->whereHas('project', fn ($q) => self::scopeActive($q))
            ->with('project:id,code,title')
            ->orderBy('id')
            ->get()
            ->filter(fn (ProjectUser $row) => $row->project)
            ->groupBy('user_id')
            ->map(fn (Collection $group) => $group->unique('project_id')->map(fn (ProjectUser $row) => [
                'id' => (int) $row->project->id,
                'code' => $row->project->code,
                'title' => $row->project->title,
            ])->values()->all())
            ->all();
    }
}
