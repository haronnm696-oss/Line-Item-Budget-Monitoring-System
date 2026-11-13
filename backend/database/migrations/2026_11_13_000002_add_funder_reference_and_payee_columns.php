<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: the funder's own reference number for a project (e.g. the
 * grant agreement number) and the payee on an expense record.
 */
class AddFunderReferenceAndPayeeColumns extends Migration
{
    public function up()
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('funder_reference', 100)->nullable()->after('funding_agency_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('payee')->nullable()->after('reference_no');
        });
    }

    public function down()
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('payee');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('funder_reference');
        });
    }
}
