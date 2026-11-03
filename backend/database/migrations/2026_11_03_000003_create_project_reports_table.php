<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Progress, terminal, and financial report deadlines per project.
     * The Program Leader dashboard uses these rows for its reporting
     * calendar and for each project's On time / Due soon / Late label.
     */
    public function up(): void
    {
        Schema::create('project_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['progress', 'terminal', 'financial'])->default('progress');
            $table->string('title');
            $table->date('due_date');
            $table->date('submitted_at')->nullable();
            $table->string('document_path')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'due_date']);
            $table->index(['type', 'due_date']);

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_reports');
    }
};
