<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class UserTableSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = base_path('database/seeders/users.csv');

        if (!File::exists($csvPath)) {
            $this->command->error("File users.csv tidak ditemukan di database/seeders!");
            return;
        }

        $csvData = File::get($csvPath);
        $rows = array_map('str_getcsv', explode("\n", str_replace("\r", "", $csvData)));
        $header = array_shift($rows);

        foreach ($rows as $row) {
            if (count($row) >= 4 && !empty($row[0])) {
                User::updateOrCreate(
                    ['email' => trim($row[1]) . '@indosafety.com'], // Jika di lama tidak ada email, generate format email
                    [
                        'name'     => trim($row[1]),
                        'password' => Hash::make(trim($row[2])), // Hash password untuk standar Laravel
                    ]
                );
            }
        }

        $this->command->info("Data Users berhasil diimpor!");
    }
}