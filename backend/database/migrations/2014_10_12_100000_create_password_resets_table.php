<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePasswordResetsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Aiven MySQL enforces sql_require_primary_key = 1 by default, which
        // rejects tables without a primary key. Disable it for this session so
        // the password_resets table (which has no natural primary key) can be
        // created both during fresh migrations and when importing the dump.
        try {
            \Illuminate\Support\Facades\DB::statement('SET SESSION sql_require_primary_key = 0');
        } catch (\Throwable $e) {
            // Ignore on database engines that do not support this variable.
        }
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('password_resets');
    }
}
