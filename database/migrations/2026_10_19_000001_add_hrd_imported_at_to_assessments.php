<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When HRD's attendance import wrote this assessment. Set = attendance + tardiness (Assessment::HRD_COLUMNS) are locked
 * for the Penilai and the approver (Assessment::hrdLocked()); the assessment itself stays a Draft.
 * Additive: a new empty column, existing rows untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('assessments', 'hrd_imported_at')) {
            Schema::table('assessments', function (Blueprint $table) {
                $table->timestamp('hrd_imported_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('hrd_imported_at');
        });
    }
};
