<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HRD sanctions (SP 1 / SP 2 / SP 3 / SP Pertama & Terakhir ...) imported from the HRD sanction list, and the lock they
 * put on Point 6 (Kepatuhan Keselamatan) of Assessment 1.
 *
 *  - hrd_sanctions: one row per sanction letter, history kept per employee + period. source_key
 *    ("NIK|YYYY-MM-DD|NO SURAT|JENIS") makes a re-import update instead of duplicate.
 *  - assessments.sanction_locked_at: set = score_safety and its note come from HRD sanctions and are LOCKED for the
 *    Penilai and the approver (Assessment::sanctionLocked()). Separate from hrd_imported_at (Point 1 & 2).
 *
 * Additive only: a new table and two empty columns, existing rows untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hrd_sanctions')) {
            Schema::create('hrd_sanctions', function (Blueprint $table) {
                $table->id();
                $table->string('source_key', 191)->unique();
                $table->string('employee_id', 30)->index();
                $table->string('period', 7);
                $table->date('sanction_date');
                $table->string('letter_no', 80)->nullable();
                $table->string('sanction_type', 30);
                $table->text('case_text')->nullable();          // KASUS as in the HRD file
                $table->string('case_summary', 450);            // short version used in the Point 6 note
                $table->string('article', 150)->nullable();     // KETERANGAN (e.g. BAB VII Pasal 22 ...)
                $table->string('name_in_file', 150)->nullable();
                $table->string('section_in_file', 60)->nullable();
                $table->string('imported_by', 50)->nullable();
                $table->timestamps();
                $table->index(['period', 'employee_id']);
            });
        }

        Schema::table('assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('assessments', 'sanction_locked_at')) {
                $table->timestamp('sanction_locked_at')->nullable();
            }
            if (! Schema::hasColumn('assessments', 'sanction_locked_by')) {
                $table->string('sanction_locked_by', 50)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(['sanction_locked_at', 'sanction_locked_by']);
        });
        Schema::dropIfExists('hrd_sanctions');
    }
};
