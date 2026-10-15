<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Employee; // <-- PAS TIKAN BARIS INI ADA
use Illuminate\Support\Facades\File;

class EmployeesTableSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = base_path('database/seeders/karyawan.csv');

        if (!File::exists($csvPath)) {
            $this->command->error("File karyawan.csv tidak ditemukan di database/seeders!");
            return;
        }

        $csvData = File::get($csvPath);
        $rows = array_map('str_getcsv', explode("\n", str_replace("\r", "", $csvData)));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            if (count($row) >= 6 && !empty($row[0])) {
                Employee::updateOrCreate(
                    ['id_employee' => trim($row[0])],
                    [
                        'name'        => trim($row[1]),
                        'department'  => trim($row[2]),
                        'position'    => trim($row[3]),
                        'grade'       => trim($row[4]),
                        'status'      => trim($row[5]),
                    ]
                );
            }
        }

        $this->command->info("Data karyawan dari CSV berhasil diimpor ke Supabase!");
    }
}