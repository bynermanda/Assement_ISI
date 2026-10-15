<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1 = Penilai 1 (assesses), 2 = Penilai 2 (approves / edits), 3 = both
        if (! Schema::hasColumn('users', 'stage')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedSmallInteger('stage')->nullable()->after('grade');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('stage'));
    }
};
