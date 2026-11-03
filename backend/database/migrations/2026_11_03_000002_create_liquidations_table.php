<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liquidation reports submitted after a tranche release. A liquidation
     * can be pending approval, approved, or rejected. Approval decisions
     * also write a row in approval_requests so the unified Program Leader
     * inbox has one queue for every approval type.
     */
    public function up(): void
    {
        Schema::create('liquidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_tranche_id')->nullable()
                  ->constrained('project_tranches')->nullOnDelete();
            $table->string('title');
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('due_date');
            $table->date('submitted_at')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('document_path')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('reviewed_by')->nullable()
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['status', 'due_date']);

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidations');
    }
};
