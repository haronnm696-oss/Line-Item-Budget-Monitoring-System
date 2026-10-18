<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Line-Item Budget categories under a project.
     * Each row is one approved LIB line item (e.g. "Honoraria - RA",
     * "Travel Expenses", "Supplies & Materials") tagged as PS or MOOE,
     * with its own allocated ceiling. Expenses are charged against
     * these rows, which is what lets us prevent overspending per item
     * (not just per PS/MOOE bucket).
     */
    public function up(): void
    {
        Schema::create('lib_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('category_type', ['PS', 'MOOE']);
            $table->string('line_item_name'); // e.g. "Honoraria", "Travel", "Supplies"
            $table->string('line_item_code')->nullable(); // COA/agency line-item code if applicable
            $table->decimal('allocated_amount', 14, 2);
            $table->timestamps();

            $table->index(['project_id', 'category_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lib_categories');
    }
};
