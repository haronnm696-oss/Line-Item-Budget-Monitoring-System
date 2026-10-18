<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();  // internal project/tracking code
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('funding_agency_id')->nullable()
                  ->constrained('funding_agencies')->nullOnDelete();
            $table->decimal('total_approved_budget', 14, 2)->default(0);
            $table->date('start_date');
            $table->date('end_date'); // project period, max ~12 months per payroll cycle rules
            $table->enum('status', ['planning', 'ongoing', 'completed', 'terminated'])
                  ->default('planning');
            $table->timestamps();
            $table->softDeletes(); // preserve for audit trail even if archived
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
