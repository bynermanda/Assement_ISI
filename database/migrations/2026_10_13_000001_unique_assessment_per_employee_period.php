<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One assessment per employee per period; also needed by the bulk upsert (ON CONFLICT). */
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->unique(['employee_id', 'period'], 'assessments_employee_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', fn (Blueprint $t) => $t->dropUnique('assessments_employee_period_unique'));
    }
};
