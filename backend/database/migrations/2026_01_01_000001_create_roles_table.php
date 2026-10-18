<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles: Program Leader, Project Leader, Research Assistant,
     * Researcher, Finance/Administrative Staff.
     * Roles are assigned per-project via project_user, not globally,
     * since a person can hold different roles on different projects.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // e.g. "Project Leader"
            $table->string('slug')->unique(); // e.g. "project-leader"
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Seed the five defined personas
        \Illuminate\Support\Facades\DB::table('roles')->insert([
            ['name' => 'Program Leader', 'slug' => 'program-leader', 'description' => 'Oversees multiple research projects; monitors macro-level spending.', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Project Leader', 'slug' => 'project-leader', 'description' => 'Manages a specific project; tracks fund utilization and generates reports.', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Research Assistant', 'slug' => 'research-assistant', 'description' => 'Encodes financial data, records expenses, computes payroll.', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Researcher', 'slug' => 'researcher', 'description' => 'Views financial data and itemized logs for designated work.', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Finance/Administrative Staff', 'slug' => 'finance-admin', 'description' => 'Verifies transactions and supports final reporting.', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
