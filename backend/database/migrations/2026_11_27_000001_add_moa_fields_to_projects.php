<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project set-up from the Memorandum of Agreement (MOA): the MOA number and
 * date, the date the funder's R&D committee approved the project, and the
 * fund source printed on Form B ("Chargeable against <fund source>").
 * All optional. Additive only.
 */
class AddMoaFieldsToProjects extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'moa_number')) {
                $table->string('moa_number', 100)->nullable();
            }
            if (! Schema::hasColumn('projects', 'moa_date')) {
                $table->date('moa_date')->nullable();
            }
            if (! Schema::hasColumn('projects', 'rd_committee_approved_date')) {
                $table->date('rd_committee_approved_date')->nullable();
            }
            if (! Schema::hasColumn('projects', 'fund_source')) {
                $table->string('fund_source', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Additive migration: columns are kept (production data).
    }
}
