<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase requests no longer stay pending forever: after "approved"
 * (workflow_status = endorsed) a request is either "fulfilled" (an expense
 * of the same project and line item is linked to it) or "cancelled" (with a
 * reason). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->timestamp('fulfilled_at')->nullable()->after('return_reason');
            $table->foreignId('fulfilled_by')->nullable()->after('fulfilled_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('fulfilled_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable()->after('cancelled_by');
        });

        Schema::table('expenses', function (Blueprint $table) {
            // The approved purchase request this expense fulfils.
            $table->foreignId('purchase_request_id')->nullable()->after('lib_category_id')->constrained('purchase_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_request_id');
        });

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfilled_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['fulfilled_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
