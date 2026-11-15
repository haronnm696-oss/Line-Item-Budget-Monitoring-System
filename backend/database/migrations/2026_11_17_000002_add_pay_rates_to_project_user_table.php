<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's pay on a project: monthly rate and monthly allowance. Payroll
 * computation creates a row for each active member who has a monthly rate.
 * Nullable and additive; existing assignments keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_user', function (Blueprint $table) {
            $table->decimal('monthly_rate', 15, 2)->nullable()->after('role_id');
            $table->decimal('allowance', 15, 2)->nullable()->after('monthly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('project_user', function (Blueprint $table) {
            $table->dropColumn(['monthly_rate', 'allowance']);
        });
    }
};
