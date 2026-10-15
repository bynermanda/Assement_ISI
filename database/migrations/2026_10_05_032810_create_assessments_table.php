<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id');
            $table->foreign('employee_id')->references('id_employee')->on('employees')->onDelete('cascade');
            
            $table->string('period'); // Format YYYY-MM
            $table->integer('score_work_quality')->default(0);
            $table->integer('score_discipline')->default(0);
            $table->integer('score_attitude')->default(0);
            $table->decimal('final_score', 5, 2)->default(0.00);
            
            $table->enum('status', ['Draft', 'Pending', 'Verified', 'Rejected'])->default('Draft');
            $table->text('rejection_note')->nullable();
            
            $table->string('created_by')->nullable();
            $table->string('verified_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};