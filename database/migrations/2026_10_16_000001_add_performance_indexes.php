<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the columns the pages filter and join on. Additive only (no data touched), safe to re-run.
 * PostgreSQL does not index foreign keys by itself, hence the a2_participants ones.
 */
return new class extends Migration
{
    private const INDEXES = [
        'assessments_period_status_index'          => 'assessments (period, status)',          // dashboard counts, exports, admin list
        'employees_status_department_index'        => 'employees (status, department)',        // active employees per department
        'a2_participants_assessor_id_index'        => 'a2_participants (assessor_id)',         // "who do I assess"
        'a2_participants_approver_id_index'        => 'a2_participants (approver_id)',         // "who do I approve"
        'a2_participants_unit_id_index'            => 'a2_participants (unit_id)',
        'a2_criteria_unit_id_sort_index'           => 'a2_criteria (unit_id, sort)',           // criteria of a unit, in order
        'a2_assessments_status_period_index'       => 'a2_assessments (status, period)',       // pending lists, exports
        'a2_assessments_unit_id_index'             => 'a2_assessments (unit_id)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
