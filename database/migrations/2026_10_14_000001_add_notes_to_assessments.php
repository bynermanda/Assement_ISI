<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 備註&評價特別說明 / Catatan: { "score_work_quality": "...", ..., "general": "..." } */
    public function up(): void
    {
        if (! Schema::hasColumn('assessments', 'notes')) {
            Schema::table('assessments', function (Blueprint $table) {
                $table->json('notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('assessments', fn (Blueprint $t) => $t->dropColumn('notes'));
    }
};
