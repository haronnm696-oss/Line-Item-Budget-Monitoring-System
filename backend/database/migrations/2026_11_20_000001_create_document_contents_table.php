<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * File bytes of documents stored in the database (DOCUMENTS_DISK=database).
 * A separate table so the bytes are never loaded by queries on documents.
 * Additive: no existing column or row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_contents', function (Blueprint $table) {
            $table->id(); // Aiven requires a primary key (sql_require_primary_key)
            $table->foreignId('document_id')->unique()->constrained('documents')->cascadeOnDelete();
            $table->binary('content');
            $table->char('sha256', 64)->index();
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->nullable();
        });

        // binary() is a 64 KB BLOB in MySQL; files go up to 5 MB, so use
        // MEDIUMBLOB (up to 16 MB). SQLite (tests) keeps its BLOB.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE document_contents MODIFY content MEDIUMBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_contents');
    }
};
