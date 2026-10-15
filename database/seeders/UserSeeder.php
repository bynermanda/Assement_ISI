<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Only makes sure the admin (HRD) account exists. It never touches other users.
 *
 * Reviewer accounts are NOT created here any more: open Users in the app and press
 * "Create accounts" (one account per employee of grade 4-9, login = NIK).
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('SEED_DEFAULT_PASSWORD') ?: config('assessment.default_password') ?: throw new \RuntimeException('Set SEED_DEFAULT_PASSWORD or DEFAULT_ACCOUNT_PASSWORD in .env first.');

        if (User::where('username', 'admin')->exists()) {
            $this->command?->info('Admin account already exists, nothing changed.');

            return;
        }

        (new User())->forceFill([
            'username'             => 'admin',
            'name'                 => 'Administrator (HRD)',
            'role'                 => 'admin',
            'password'             => Hash::make($password),
            'must_change_password' => true,
        ])->save();

        $this->command?->info("Admin created: admin / {$password} (must be changed at first login).");
    }
}
