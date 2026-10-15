<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('criteria_descriptions', function (Blueprint $table) {
            $table->id('id_desc');
            $table->string('department'); // Merujuk ke kolom 'bagian' di MySQL
            $table->text('desc1')->nullable(); // Kehadiran
            $table->text('desc2')->nullable(); // Kedisiplinan
            $table->text('desc3')->nullable(); // Kualitas Kerja / Planning
            $table->text('desc4')->nullable(); // Pencapaian
            $table->text('desc5')->nullable(); // Sikap Kerja
            $table->text('desc6')->nullable(); // Usulan/Saran
            $table->timestamps();
            $table->string('sub_department')->nullable()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('criteria_descriptions', function (Blueprint $table) {
        $table->dropColumn('sub_department');
        });
    }
};