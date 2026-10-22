<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Schema changes that were once made directly on the live database and never had a migration, so a fresh
 * `php artisan migrate` stopped at 2026_10_18 (column "score_attendance" does not exist). Reference: database/schema/schema-public.sql.
 *  - assessments: the score columns for points 1, 2, 4 and 6 (attendance, tardiness, efficiency, safety)
 *  - assessments.score_discipline may be empty (default 0 kept)
 *  - employees.status accepts Active / Resigned (the values the app writes), not Aktif / Resign
 * Idempotent: on a database that already has these (the live one) it changes nothing.
 */
return new class extends Migration
{
    private const SCORES = ['score_attendance', 'score_tardiness', 'score_efficiency', 'score_safety'];

    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            foreach (self::SCORES as $column) {
                if (! Schema::hasColumn('assessments', $column)) {
                    // NOT NULL DEFAULT 0 like the other scores; 2026_10_18 then makes them nullable without a default
                    $table->integer($column)->default(0);
                }
            }
        });

        DB::statement('ALTER TABLE assessments ALTER COLUMN score_discipline DROP NOT NULL');

        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_status_check');
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status::text = ANY (ARRAY['Active'::character varying, 'Resigned'::character varying]::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_status_check');
        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_status_check CHECK (status::text = ANY (ARRAY['Aktif'::character varying, 'Resign'::character varying]::text[]))");

        DB::statement('UPDATE assessments SET score_discipline = 0 WHERE score_discipline IS NULL');
        DB::statement('ALTER TABLE assessments ALTER COLUMN score_discipline SET NOT NULL');

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn(self::SCORES);
        });
    }
};
