<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Optional first-admin bootstrap for hosts without SSH: set INITIAL_ADMIN_EMAIL
     * and INITIAL_ADMIN_PASSWORD in .env, run `php artisan db:seed --force` once,
     * then remove both values from .env. Prefer `php artisan app:create-admin`.
     */
    public function run(): void
    {
        $email = config('gscraper.initial_admin.email');
        $password = config('gscraper.initial_admin.password');

        if (User::query()->exists()) {
            $this->command?->info('Users already exist; nothing to seed.');

            return;
        }
        if (! $email || ! $password || strlen($password) < 10) {
            $this->command?->warn('No users yet. Run `php artisan app:create-admin`, or set INITIAL_ADMIN_EMAIL and INITIAL_ADMIN_PASSWORD (10+ chars) and seed again.');

            return;
        }

        $user = new User(['name' => 'Admin', 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();
        $this->command?->info("Admin {$email} created. Now remove INITIAL_ADMIN_PASSWORD from .env.");
    }
}
