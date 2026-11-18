<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Releases keep the number users see ("Release 2") for good: it is stored
 * on the release and never changes when dates change or a release is
 * removed. projects.last_release_number is the highest number a project has
 * ever given, so a removed release's number is not given again.
 *
 * Additive only: two nullable columns, backfilled once per project by
 * planned date (undated last), then id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_tranches', 'release_number')) {
            Schema::table('project_tranches', function (Blueprint $table) {
                $table->unsignedInteger('release_number')->nullable()->after('sequence');
            });
        }
        if (! Schema::hasColumn('projects', 'last_release_number')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->unsignedInteger('last_release_number')->nullable();
            });
        }

        $this->backfill();
    }

    /** Number releases that have none yet; numbered ones are left alone. */
    public function backfill(): void
    {
        $projectIds = DB::table('project_tranches')->whereNull('release_number')->distinct()->pluck('project_id');

        foreach ($projectIds as $projectId) {
            DB::transaction(function () use ($projectId) {
                $rows = DB::table('project_tranches')->where('project_id', $projectId)
                    ->get(['id', 'release_number', 'planned_release_date']);
                $last = (int) max(
                    (int) $rows->max('release_number'),
                    (int) DB::table('projects')->where('id', $projectId)->value('last_release_number')
                );
                $unnumbered = $rows->whereNull('release_number')
                    ->sortBy(fn ($row) => [$row->planned_release_date === null ? 1 : 0, (string) $row->planned_release_date, (int) $row->id])
                    ->values();

                foreach ($unnumbered as $row) {
                    DB::table('project_tranches')->where('id', $row->id)->update(['release_number' => ++$last]);
                }
                DB::table('projects')->where('id', $projectId)->update(['last_release_number' => $last]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('project_tranches', fn (Blueprint $table) => $table->dropColumn('release_number'));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('last_release_number'));
    }
};
