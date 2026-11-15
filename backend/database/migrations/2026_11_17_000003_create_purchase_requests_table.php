<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase requests (paper: "Upload Purchase Request" with "Invoice Receipt").
 * Workflow: draft -> submitted -> endorsed ("Approved by Project Leader") or
 * returned. Quotations / invoices are documents. Soft deletes keep history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lib_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('description', 500);
            $table->unsignedInteger('quantity');
            $table->decimal('estimated_amount', 15, 2);

            $table->string('workflow_status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('pl_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pl_reviewed_at')->nullable();
            $table->text('return_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'workflow_status']);
            $table->index(['lib_category_id', 'workflow_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};
