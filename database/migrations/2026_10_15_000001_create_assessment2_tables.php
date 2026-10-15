<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment 2 (leaders): criteria, weights and targets differ per unit (department sheet of the HRD template),
 * every participant has his own assessor and approver (employees, TKA or other people with an account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a2_units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();            // "ASSY 1", "QA - Audit", "ADVISOR"
            $table->string('department')->nullable();    // employees.department when the unit is a department
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('a2_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('a2_units')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('group_zh')->nullable();
            $table->string('group_id')->nullable();
            $table->text('name_zh')->nullable();
            $table->text('name_id');
            $table->decimal('weight', 5, 2);             // percent: 15.00 = 15%
            $table->text('target')->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
        });

        Schema::create('a2_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('a2_units')->cascadeOnDelete();
            $table->string('employee_id')->unique();
            $table->foreignId('assessor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('a2_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('a2_units');
            $table->string('employee_id');
            $table->string('period');                    // YYYY-MM
            $table->json('scores');                      // { criterion id: points }
            $table->json('criteria_snapshot');           // the criteria as they were when scored
            $table->decimal('final_score', 5, 2);
            $table->string('status');
            $table->json('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'period']);
        });

        if (! Schema::hasColumn('users', 'job_title')) {
            Schema::table('users', fn (Blueprint $t) => $t->string('job_title')->nullable());   // e.g. "General Manager" for a TKA account
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('a2_assessments');
        Schema::dropIfExists('a2_participants');
        Schema::dropIfExists('a2_criteria');
        Schema::dropIfExists('a2_units');
        if (Schema::hasColumn('users', 'job_title')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('job_title'));
        }
    }
};
