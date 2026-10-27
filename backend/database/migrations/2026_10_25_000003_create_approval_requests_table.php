<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dashboard approval queue: fund-release/disbursement requests and
     * budget realignment requests that a Program Leader can approve or
     * reject without leaving the dashboard.
     */
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_tranche_id')->nullable()
                  ->constrained('project_tranches')->nullOnDelete();
            $table->string('type'); // disbursement | budget_realignment | payroll
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('requested_by')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
