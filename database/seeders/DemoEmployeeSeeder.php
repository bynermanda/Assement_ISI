<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Optional: a few test employees in PURCHASING so you can try the whole workflow.
 * Run with: php artisan db:seed --class=DemoEmployeeSeeder
 */
class DemoEmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $employees = [
            ['DEMO001', 'Budi Santoso',  'Grade 4', 'Staff'],
            ['DEMO002', 'Siti Rahayu',   'Grade 3', 'Senior Staff'],
            ['DEMO003', 'Andi Pratama',  'Grade 5', 'Operator'],
            ['DEMO004', 'Dewi Lestari',  'Grade 4', 'Yunior Staff'],
        ];

        foreach ($employees as [$id, $name, $position, $grade]) {
            DB::table('employees')->updateOrInsert(
                ['id_employee' => $id],
                [
                    'name'       => $name,
                    'department' => 'PURCHASING',
                    'position'   => $position,
                    'grade'      => $grade,
                    'status'     => 'Active',
                ]
            );
        }

        $this->command->info('4 demo employees added to PURCHASING.');
    }
}
