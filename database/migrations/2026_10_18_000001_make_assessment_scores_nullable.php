<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Criteria scores may be empty = "not scored yet". Needed by the attendance import: it fills only attendance and
 * tardiness as a Draft; the other criteria must stay empty (a stored 0 would show as grade D in every form).
 * Only relaxes the columns: no data is changed.
 */
return new class extends Migration
{
    private const COLUMNS = ['score_attendance', 'score_tardiness', 'score_work_quality', 'score_efficiency', 'score_attitude', 'score_safety'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            DB::statement("ALTER TABLE assessments ALTER COLUMN {$column} DROP NOT NULL");
            DB::statement("ALTER TABLE assessments ALTER COLUMN {$column} DROP DEFAULT");
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            DB::statement("UPDATE assessments SET {$column} = 0 WHERE {$column} IS NULL");
            DB::statement("ALTER TABLE assessments ALTER COLUMN {$column} SET DEFAULT 0");
            DB::statement("ALTER TABLE assessments ALTER COLUMN {$column} SET NOT NULL");
        }
    }
};
