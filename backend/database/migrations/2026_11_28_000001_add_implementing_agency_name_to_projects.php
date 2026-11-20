<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional Implementing Agency printed on R&D Form B (e.g. "MSU-Marawi");
 * blank = the IMPLEMENTING_AGENCY setting. Additive only.
 */
class AddImplementingAgencyNameToProjects extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('projects', 'implementing_agency_name')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('implementing_agency_name', 255)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Additive migration: the column is kept (production data).
    }
}
