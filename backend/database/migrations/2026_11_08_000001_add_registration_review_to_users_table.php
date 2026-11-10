<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddRegistrationReviewToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Rejected self-registrations stay on record instead of being deleted.
            $table->unsignedBigInteger('registration_reviewed_by')->nullable()->after('is_active');
            $table->timestamp('registration_rejected_at')->nullable()->after('registration_reviewed_by');
            $table->text('registration_rejection_reason')->nullable()->after('registration_rejected_at');
            $table->timestamp('last_login_at')->nullable()->after('registration_rejection_reason');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'registration_reviewed_by',
                'registration_rejected_at',
                'registration_rejection_reason',
                'last_login_at',
            ]);
        });
    }
}
