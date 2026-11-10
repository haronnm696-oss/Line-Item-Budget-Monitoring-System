<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReasonToAuditTrailsTable extends Migration
{
    public function up()
    {
        Schema::table('audit_trails', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('new_values');
        });
    }

    public function down()
    {
        Schema::table('audit_trails', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
}
