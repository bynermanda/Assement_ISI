<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CriteriaDescription;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

class CriteriaDescriptionSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = base_path('database/seeders/deskripsi_kriteria.csv');

        if (!File::exists($csvPath)) {
            $this->command->error("File deskripsi_kriteria.csv tidak ditemukan!");
            return;
        }

        // Hapus data lama terlebih dahulu agar bersih
        DB::table('criteria_descriptions')->truncate();

        $file = fopen($csvPath, 'r');
        $header = fgetcsv($file); // Skip baris pertama (header)

        while (($row = fgetcsv($file)) !== FALSE) {
            if (isset($row[1]) && !empty($row[1])) {
                CriteriaDescription::create([
                    'id_desc'    => (int) $row[0],
                    'department' => trim($row[1]),        // 'bagian'
                    'desc1'      => $row[2] ?? null,
                    'desc2'      => $row[3] ?? null,
                    'desc3'      => $row[4] ?? null,
                    'desc4'      => $row[5] ?? null,
                    'desc5'      => $row[6] ?? null,
                    'desc6'      => $row[7] ?? null,
                ]);
            }
        }

        fclose($file);
        $this->command->info("Data Deskripsi Kriteria berhasil diimpor dengan sempurna!");
    }
}