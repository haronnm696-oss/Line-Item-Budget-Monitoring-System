<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add dual-funding structure columns to projects:
     * implementing agency counterpart + funding agency amount.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('implementing_agency_counterpart', 14, 2)
                  ->default(0)
                  ->after('total_approved_budget')
                  ->comment('Implementing agency (MSU-Marawi) counterpart share');
            $table->decimal('funding_agency_amount', 14, 2)
                  ->default(0)
                  ->after('implementing_agency_counterpart')
                  ->comment('Funding agency (MOST-BARMM) grant amount');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['implementing_agency_counterpart', 'funding_agency_amount']);
        });
    }
};
