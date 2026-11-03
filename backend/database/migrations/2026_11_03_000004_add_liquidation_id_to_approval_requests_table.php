<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link the unified approval inbox to a liquidation record. Nullable so
     * existing fund-release and realignment approvals are unaffected.
     */
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->foreignId('liquidation_id')->nullable()
                  ->after('project_tranche_id')
                  ->constrained('liquidations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('liquidation_id');
        });
    }
};
