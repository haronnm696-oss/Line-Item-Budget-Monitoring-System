<?php

namespace App\Models;

use App\Support\ReleaseWording;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A planned fund release of a project. The table and API keep the name
 * "tranche"; users read "Release 1", "Release 2"…
 *
 * The number is stored (release_number). Editing a date never renumbers.
 * A number becomes permanent once the release is released or named in a
 * fund release / liquidation request; such a release cannot be removed.
 * Removing a scheduled release that was never requested gives its number
 * back: projects.last_release_number is recomputed from the releases still
 * in use, so the next new release gets the lowest free number after that.
 * Lists are still ordered by planned date (sortByPlannedDate).
 */
class ProjectTranche extends Model
{
    protected $fillable = [
        'project_id', 'sequence', 'release_number', 'name', 'amount', 'percentage',
        'status', 'planned_release_date', 'released_at', 'notes',
    ];

    protected $casts = [
        'release_number' => 'integer',
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'planned_release_date' => 'date',
        'released_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tranche) {
            static::assignReleaseNumber($tranche);
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function approvalRequests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    /** Older liquidation records that name this release. */
    public function liquidations(): HasMany
    {
        return $this->hasMany(Liquidation::class);
    }

    public function isReleased(): bool
    {
        return $this->status === 'released';
    }

    /**
     * Once requested (a fund release or liquidation names it) or released,
     * the number is permanent: the release is never removed or renumbered.
     */
    public function isNumberPermanent(): bool
    {
        return $this->isReleased()
            || $this->released_at !== null
            || $this->approvalRequests()->exists()
            || $this->liquidations()->exists();
    }

    /** "Release 2", from the stored number. */
    public function releaseLabel(): string
    {
        return ReleaseWording::label($this->release_number ?? (int) $this->sequence);
    }

    /** What the stored name adds beyond the number ("Mobilization"), or null. */
    public function releaseMilestone(): ?string
    {
        return ReleaseWording::milestone($this->name);
    }

    /**
     * Planned date first (undated last), then release number, then id.
     *
     * @param  Collection<int, self>  $tranches
     * @return Collection<int, self>
     */
    public static function sortByPlannedDate(Collection $tranches): Collection
    {
        return $tranches->sortBy(fn (self $t) => [
            $t->planned_release_date?->format('Y-m-d') ?? '9999-12-31',
            (int) ($t->release_number ?? PHP_INT_MAX),
            (int) $t->id,
        ])->values();
    }

    /**
     * The project's next number: one more than the highest number still in
     * use (see recomputeLastReleaseNumber). The project row is locked, so two
     * releases added at once get different numbers.
     */
    private static function assignReleaseNumber(self $tranche): void
    {
        if (! $tranche->project_id) {
            return;
        }

        $given = DB::table('projects')->where('id', $tranche->project_id)->lockForUpdate()->value('last_release_number');
        $last = max(
            (int) $given,
            (int) static::query()->where('project_id', $tranche->project_id)->max('release_number')
        );
        if ($tranche->release_number === null) {
            $tranche->release_number = $last + 1;
        }

        // Bookkeeping, not a project edit: no audit entry, updated_at untouched.
        DB::table('projects')->where('id', $tranche->project_id)
            ->update(['last_release_number' => max($last, (int) $tranche->release_number)]);
    }

    /**
     * After a never-requested scheduled release is removed, lower the
     * project's high-water mark to the highest number still in use. The next
     * release then takes that number + 1. Referenced and released releases
     * stay in the table, so their numbers are never handed out again.
     */
    public static function recomputeLastReleaseNumber(int $projectId): int
    {
        $last = (int) static::query()->where('project_id', $projectId)->max('release_number');

        // Bookkeeping, not a project edit: no audit entry, updated_at untouched.
        DB::table('projects')->where('id', $projectId)->update(['last_release_number' => $last]);

        return $last;
    }
}
