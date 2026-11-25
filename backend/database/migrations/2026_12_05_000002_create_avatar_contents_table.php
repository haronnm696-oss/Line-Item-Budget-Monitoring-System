<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avatar bytes when documents are stored in the database
 * (DOCUMENTS_DISK=database, the production setting): Render's disk is wiped
 * on every deploy, so avatars live next to document_contents in MySQL.
 * One row per user; replaced on each upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('avatar_contents')) {
            return;
        }

        Schema::create('avatar_contents', function (Blueprint $table) {
            $table->id(); // Aiven requires a primary key (sql_require_primary_key)
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('mime', 50)->default('image/jpeg');
            $table->binary('content');
            $table->unsignedInteger('size')->default(0);
            $table->timestamp('created_at')->nullable();
        });

        // binary() is a 64 KB BLOB in MySQL; use MEDIUMBLOB to be safe.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE avatar_contents MODIFY content MEDIUMBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_contents');
    }
};
