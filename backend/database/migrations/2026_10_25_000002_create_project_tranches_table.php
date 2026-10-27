<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone-based fund releases for a project. A tranche is either
     * upcoming, due, released, or delayed; released tranches carry the
     * actual release date used by the dashboard timeline.
     */
    public function up(): void
    {
        Schema::create('project_tranches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->decimal('percentage', 5, 2)->nullable();
            $table->enum('status', ['upcoming', 'due', 'released', 'delayed'])->default('upcoming');
            $table->date('planned_release_date')->nullable();
            $table->date('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'sequence']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_tranches');
    }
};
