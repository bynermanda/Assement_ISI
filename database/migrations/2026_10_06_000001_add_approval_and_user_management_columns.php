<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // --- users: login columns + "must change password" flag ---------------
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('assistant');
            }
            if (! Schema::hasColumn('users', 'department')) {
                $table->string('department')->nullable();
            }
            if (! Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false);
            }
            if (Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->change();   // login uses username
            }
        });

        // --- assessments: who gave the final approval -------------------------
        Schema::table('assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('assessments', 'approved_by')) {
                $table->string('approved_by')->nullable();
            }
        });

        // --- assessments.status must allow the new "Approved" value -----------
        DB::statement('ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_status_check');
        DB::statement("ALTER TABLE assessments ADD CONSTRAINT assessments_status_check
                       CHECK (status IN ('Draft','Pending','Verified','Approved','Rejected'))");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('approved_by');
        });
    }
};
