<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment 1 special routes: some employees are not assessed / approved by their department's Penilai 1 / 2
 * accounts but by the accounts named here (e.g. ASSY line leaders: assessed by assyN.penilai2, approved by the manager).
 * A route matches one employee (employee_id) or a department + job titles (jabatan, employees.grade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a1_routes', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->nullable();       // one person ...
            $table->string('department')->nullable();        // ... or everybody of this department
            $table->json('jabatans')->nullable();            //     with one of these job titles
            $table->foreignId('assessor_id')->constrained('users');
            $table->foreignId('approver_id')->constrained('users');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index('employee_id');
            $table->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a1_routes');
    }
};
