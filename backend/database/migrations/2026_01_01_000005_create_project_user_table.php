<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assigns a user to a project WITH a specific role for that project.
     * This is the core of the RBAC model: permissions are resolved as
     * (user, project) -> role, not as a single global role on the user.
     */
    public function up(): void
    {
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['project_id', 'user_id', 'role_id'], 'project_user_role_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
    }
};
