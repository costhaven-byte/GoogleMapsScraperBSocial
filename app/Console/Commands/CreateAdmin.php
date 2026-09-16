<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('app:create-admin {email? : Email address to sign in with} {--name= : Display name}')]
#[Description('Create an admin account (run once over SSH after the first deployment)')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email');
        $name = $this->option('name') ?: $this->ask('Name', 'Admin');
        $password = $this->secret('Password (min 10 characters, letters and numbers)');
        $confirm = $this->secret('Repeat password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'email' => ['required', 'email', 'max:254', 'unique:users,email'],
                'name' => ['required', 'string', 'max:100'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();
        $this->info("Admin {$email} created. Sign in at ".url('/login'));

        return self::SUCCESS;
    }
}
