<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Itemized deductions per payroll row (e.g. Withholding Tax, GSIS,
     * PhilHealth, Pag-IBIG). Kept separate from payrolls so the
     * computation module can add/remove deduction types without
     * altering the payrolls table schema.
     */
    public function up(): void
    {
        Schema::create('payroll_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $table->string('deduction_type'); // e.g. "Withholding Tax", "GSIS"
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deductions');
    }
};
