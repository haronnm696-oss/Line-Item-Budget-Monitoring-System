<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printed R&D Form B fields, editable per project by the Program Leader:
 * the Monitoring Agency (blank = the funder's name) and the four
 * signatories (name and position). All optional. Additive only.
 */
class AddFormBPrintFieldsToProjects extends Migration
{
    public const COLUMNS = [
        'monitoring_agency',
        'reviewed_by_name', 'reviewed_by_position',
        'funds_certified_by_name', 'funds_certified_by_position',
        'recommending_approval_name', 'recommending_approval_position',
        'approved_by_name', 'approved_by_position',
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
