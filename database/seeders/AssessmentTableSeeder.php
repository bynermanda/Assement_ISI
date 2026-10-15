<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Assessment;
use Illuminate\Support\Facades\File;

class AssessmentTableSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = base_path('database/seeders/penilaian.csv');

        if (!File::exists($csvPath)) {
            $this->command->error("File penilaian.csv tidak ditemukan!");
            return;
        }

        $csvData = File::get($csvPath);
        $rows = array_map('str_getcsv', explode("\n", str_replace("\r", "", $csvData)));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            if (count($row) >= 5 && !empty($row[0])) {
                Assessment::create([
                    'employee_id'        => trim($row[1]), // Foreign key ke employees
                    'period'             => trim($row[2] ?? date('Y-m')),
                    'score_work_quality' => (int) trim($row[3] ?? 0),
                    'score_discipline'   => (int) trim($row[4] ?? 0),
                    'score_attitude'     => (int) trim($row[5] ?? 0),
                    'final_score'        => (float) trim($row[6] ?? 0),
                    'status'             => 'Verified', // Set default verified untuk data lama
                ]);
            }
        }

        $this->command->info("Data Penilaian Lama berhasil diimpor!");
    }
}