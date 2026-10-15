<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TKL (direct labour) or TKTL (indirect labour); only needed to tell grade 4-5 apart
        if (! Schema::hasColumn('employees', 'labor_type')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->string('labor_type', 4)->nullable()->after('status');
            });
        }

        // A reviewer account is a real person: NIK + grade + the groups (merged departments) they cover
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'nik')) {
                $table->string('nik', 30)->nullable()->after('username');
            }
            if (! Schema::hasColumn('users', 'grade')) {
                $table->unsignedSmallInteger('grade')->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'groups')) {
                $table->text('groups')->nullable()->after('grade');   // JSON array of group names
            }
        });

        // Penilai 2 may edit the scores of Penilai 1: remember who did it
        Schema::table('assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('assessments', 'edited_by')) {
                $table->string('edited_by', 50)->nullable();
            }
            if (! Schema::hasColumn('assessments', 'edited_at')) {
                $table->timestamp('edited_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('assessments', fn (Blueprint $t) => $t->dropColumn(['edited_by', 'edited_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['nik', 'grade', 'groups']));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('labor_type'));
    }
};
