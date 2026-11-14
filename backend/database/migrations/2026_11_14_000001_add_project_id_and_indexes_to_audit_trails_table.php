<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: a nullable project_id on audit entries (filled for existing
 * rows from the logged values or the record itself) and indexes for the
 * Audit trail's filters and newest-first paging. Nothing is dropped,
 * renamed, or deleted.
 */
return new class extends Migration
{
    /** Record types whose table has a project_id column. */
    private const PROJECT_TABLES = [
        'ApprovalRequest' => 'approval_requests',
        'Expense' => 'expenses',
        'LibCategory' => 'lib_categories',
        'ProjectTranche' => 'project_tranches',
        'Payroll' => 'payrolls',
        'Liquidation' => 'liquidations',
        'ProjectReport' => 'project_reports',
        'ProjectUser' => 'project_user',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('audit_trails', 'project_id')) {
            Schema::table('audit_trails', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('model_id');
            });
        }

        Schema::table('audit_trails', function (Blueprint $table) {
            $table->index('created_at', 'audit_trails_created_at_index');
            $table->index(['project_id', 'created_at'], 'audit_trails_project_created_index');
            $table->index(['user_id', 'created_at'], 'audit_trails_user_created_index');
        });

        $this->backfillProjectIds();
    }

    public function down(): void
    {
        Schema::table('audit_trails', function (Blueprint $table) {
            $table->dropIndex('audit_trails_created_at_index');
            $table->dropIndex('audit_trails_project_created_index');
            $table->dropIndex('audit_trails_user_created_index');
        });

        if (Schema::hasColumn('audit_trails', 'project_id')) {
            Schema::table('audit_trails', function (Blueprint $table) {
                $table->dropColumn('project_id');
            });
        }
    }

    /**
     * Portable (MySQL and SQLite) backfill, in chunks: logged project_id
     * first, then the record itself.
     */
    private function backfillProjectIds(): void
    {
        DB::table('audit_trails')
            ->whereNull('project_id')
            ->orderBy('id')
            ->select(['id', 'model_type', 'model_id', 'old_values', 'new_values'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $projectId = $this->projectIdFor($row);
                    if ($projectId !== null) {
                        DB::table('audit_trails')->where('id', $row->id)->update(['project_id' => $projectId]);
                    }
                }
            });
    }

    private function projectIdFor(object $row): ?int
    {
        if ($row->model_type === 'Project') {
            return (int) $row->model_id;
        }

        foreach ([$row->new_values, $row->old_values] as $json) {
            $values = is_string($json) ? json_decode($json, true) : null;
            if (is_array($values) && isset($values['project_id']) && is_numeric($values['project_id'])) {
                return (int) $values['project_id'];
            }
        }

        $table = self::PROJECT_TABLES[$row->model_type] ?? null;
        if ($table && Schema::hasTable($table) && Schema::hasColumn($table, 'project_id')) {
            $projectId = DB::table($table)->where('id', $row->model_id)->value('project_id');

            return $projectId !== null ? (int) $projectId : null;
        }

        return null;
    }
};
