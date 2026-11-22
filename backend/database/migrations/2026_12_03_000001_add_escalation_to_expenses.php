<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense verification escalation: an approved expense that no Finance/Admin
 * of the project may verify (they recorded or approved it, or the project has
 * no other Finance/Admin) goes to the Program Leader as a pending "expense"
 * approval request. The expense keeps a link to its latest such request.
 * Additive only; NULL for every existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('approval_request_id')->nullable()->after('return_reason')->constrained('approval_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_request_id');
        });
    }
};
