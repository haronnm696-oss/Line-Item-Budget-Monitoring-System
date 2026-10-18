<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * External funding sources: DOST, MOST, LGUs, etc.
     */
    public function up(): void
    {
        Schema::create('funding_agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');          // e.g. "Department of Science and Technology"
            $table->string('acronym')->nullable(); // e.g. "DOST"
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_agencies');
    }
};
