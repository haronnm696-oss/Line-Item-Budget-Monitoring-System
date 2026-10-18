<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per person, per project, per pay period (month/year).
     * lib_category_id ties this back to a PS line item so payroll
     * spend rolls up into the same PS-vs-MOOE reporting as expenses.
     */
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lib_category_id')->constrained()->cascadeOnDelete(); // must be a PS line item
            $table->foreignId('personnel_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('computed_by')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('pay_month'); // 1-12
            $table->unsignedSmallInteger('pay_year');

            $table->decimal('basic_rate', 12, 2);      // monthly rate
            $table->decimal('gross_pay', 12, 2);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2);

            $table->enum('status', ['draft', 'approved', 'released'])->default('draft');
            $table->timestamps();

            $table->unique(['project_id', 'personnel_id', 'pay_month', 'pay_year'], 'payroll_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
