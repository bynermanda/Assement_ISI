<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'section')) {
                $table->string('section')->nullable()->after('department');
                $table->index('section');
            }
        });

        // A department can have several sections, each with its own guideline text.
        // Rows without a section keep working as the guideline for the whole department.
        Schema::table('criteria_descriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('criteria_descriptions', 'section')) {
                $table->string('section')->nullable()->after('department');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['section']);
            $table->dropColumn('section');
        });

        Schema::table('criteria_descriptions', function (Blueprint $table) {
            $table->dropColumn('section');
        });
    }
};
