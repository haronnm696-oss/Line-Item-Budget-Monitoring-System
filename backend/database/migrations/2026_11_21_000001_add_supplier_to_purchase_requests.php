<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional supplier / payee of a purchase request. Linking an approved
 * request to an expense fills the expense payee with it. Additive and
 * nullable: existing requests stay blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->string('supplier', 255)->nullable()->after('estimated_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('supplier');
        });
    }
};
