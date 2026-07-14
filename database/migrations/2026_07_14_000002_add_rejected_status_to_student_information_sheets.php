<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The info sheet becomes the enrollment gateway: a coordinator can now
     * REJECT a submitted sheet (with a reason shown to the student) so they can
     * edit and resubmit. Adds 'rejected' to the status enum + a nullable reason.
     */
    public function up(): void
    {
        Schema::table('student_information_sheets', function (Blueprint $table) {
            $table->enum('submission_status', ['draft', 'submitted', 'approved', 'rejected'])
                ->default('draft')
                ->change();

            $table->string('rejection_reason', 500)->nullable()->after('submission_status');
        });
    }

    public function down(): void
    {
        Schema::table('student_information_sheets', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');

            $table->enum('submission_status', ['draft', 'submitted', 'approved'])
                ->default('draft')
                ->change();
        });
    }
};
