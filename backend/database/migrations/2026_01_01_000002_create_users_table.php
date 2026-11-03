<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fresh-deploy safety net.
     *
     * The original create_users_table migration is missing from this repo
     * (the development database was imported from a dump), which makes a
     * clean `php artisan migrate` fail at project_user's foreign key.
     * This guarded migration is a no-op on databases that already have the
     * table and creates the equivalent schema on a clean database.
     *
     * Later migrations add role_id and the personal/institutional columns,
     * so only the base columns live here.
     */
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('employee_id')->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('position')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('college_department')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally empty: this migration is a compatibility shim for
        // databases that predate the repo. Dropping users here would destroy
        // real data on rollback.
    }
};
