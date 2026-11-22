<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printed on purchase requests (MSU Purchase Request form), editable per
 * project by the Program Leader: the Requested by position (its name is
 * always the project's Project Leader), and the Noted by and Approved by
 * names and positions. All optional. Additive only.
 */
class AddPurchaseRequestSignatoriesToProjects extends Migration
{
    public const COLUMNS = [
        'pr_requested_by_position',
        'pr_noted_by_name', 'pr_noted_by_position',
        'pr_approved_by_name', 'pr_approved_by_position',
    ];

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                if (! Schema::hasColumn('projects', $column)) {
                    $table->string($column, 255)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        // Additive migration: columns are kept (production data).
    }
}
