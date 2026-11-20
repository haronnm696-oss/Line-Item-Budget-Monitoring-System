<?php

namespace App\Support;

use App\Models\ApprovalRequest;
use App\Models\Liquidation;
use App\Models\Project;
use App\Models\ProjectTranche;
use Illuminate\Support\Collection;

/**
 * The liquidation reminder on a fund release: a release whose funds are
 * released should be liquidated (an approved liquidation request, or an
 * approved row in the older liquidations table) before the next release is
 * asked for. It is a warning only; nothing is blocked.
 */
final class ReleaseLiquidation
{
    /**
     * Released releases of the project with no approved liquidation, by
     * release number. $exceptId leaves out the release being requested.
     *
     * @return Collection<int, ProjectTranche>
     */
    public static function unliquidated(Project $project, ?int $exceptId = null): Collection
    {
        $released = ProjectTranche::query()
            ->where('project_id', $project->id)
            ->where('status', 'released')
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->get();
        if ($released->isEmpty()) {
            return $released;
        }

        $ids = $released->pluck('id')->all();
        $liquidated = ApprovalRequest::query()
            ->where('project_id', $project->id)->where('type', 'liquidation')->where('status', 'approved')
            ->whereIn('project_tranche_id', $ids)->pluck('project_tranche_id')
            ->merge(Liquidation::query()
                ->where('project_id', $project->id)->where('status', 'approved')
                ->whereIn('project_tranche_id', $ids)->pluck('project_tranche_id'))
            ->map(fn ($id) => (int) $id)->unique()->all();

        return $released
            ->reject(fn (ProjectTranche $t) => in_array((int) $t->id, $liquidated, true))
            ->sortBy(fn (ProjectTranche $t) => [(int) ($t->release_number ?? $t->sequence), (int) $t->id])
            ->values();
    }

    /** "Release 1 is not yet liquidated." / "Release 1 and Release 2 are not yet liquidated." / null. */
    public static function warning(Project $project, ?int $exceptId = null): ?string
    {
        $labels = self::unliquidated($project, $exceptId)->map(fn (ProjectTranche $t) => $t->releaseLabel())->all();
        if ($labels === []) {
            return null;
        }
        if (count($labels) === 1) {
            return "{$labels[0]} is not yet liquidated.";
        }
        $last = array_pop($labels);

        return implode(', ', $labels) . " and {$last} are not yet liquidated.";
    }
}
