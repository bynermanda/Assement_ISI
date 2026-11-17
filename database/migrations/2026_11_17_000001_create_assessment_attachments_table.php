<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Files a Penilai attaches to an assessment (Assessment 1 or 2) as supporting evidence; the approver and the admin
 * only view them. The file itself lives in the private 'lampiran' storage disk (Supabase Storage), this table holds
 * where it is and what it is. See App\Support\Attachments.
 * Additive: a new table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type', 10);          // 'a1' = assessments, 'a2' = a2_assessments
            $table->unsignedBigInteger('attachable_id');
            $table->string('path', 191)->unique();          // object key in the storage disk (random name)
            $table->string('original_name', 255);
            $table->string('mime', 50);
            $table->unsignedInteger('size');                // bytes
            $table->string('uploaded_by', 50)->nullable();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
        });

        // Like every table of the app: not readable through Supabase's public API (the app connects as the owner)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assessment_attachments ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_attachments');
    }
};
