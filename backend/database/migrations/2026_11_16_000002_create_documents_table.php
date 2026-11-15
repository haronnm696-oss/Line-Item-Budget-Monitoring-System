<?php

use App\Support\LegacyAttachmentImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents (the paper's ERD "Documents" entity): files attached to a record
 * (expenses now; purchase requests and payroll later). Existing
 * expenses.attachment_path values are copied in as receipt documents; the old
 * column is kept for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 30); // receipt, invoice, quotation, payroll_file, other
            $table->string('file_name');
            $table->string('file_path');
            $table->string('disk', 40)->default('local');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime', 100)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id', 'document_type']);
        });

        $this->migrateAttachments();
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }

    /** Copies expenses.attachment_path into receipt documents (safe to rerun). */
    public function migrateAttachments(): int
    {
        return LegacyAttachmentImporter::run();
    }
};
