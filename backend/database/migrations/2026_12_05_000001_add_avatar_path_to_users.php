<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile photo: where the user's 256x256 avatar is stored
 * ("avatars/<user id>/<name>.jpg"). Nullable: no photo = initials.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_path')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('avatar_path', 255)->nullable()->default(null);
            });
        }
    }

    public function down(): void
    {
        // Additive only: the column is kept (production data).
    }
};
