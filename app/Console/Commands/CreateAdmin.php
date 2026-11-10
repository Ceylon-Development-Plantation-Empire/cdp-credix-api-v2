<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The first administrator of a new installation (the seeders never create one in production).
 * The password is typed in secretly, never passed on the command line where shell history and
 * process lists would keep it.
 */
class CreateAdmin extends Command
{
    protected $signature = 'credix:create-admin {username} {email} {--name= : display name}';

    protected $description = 'Create a Super Admin login (asks for the password)';

    public function handle(): int
    {
        $password = (string) $this->secret('Password (min 12 characters, mixed case, a number and a symbol)');
        $confirm = (string) $this->secret('Repeat the password');

        $validator = Validator::make(
            ['username' => $this->argument('username'), 'email' => $this->argument('email'), 'password' => $password, 'password_confirmation' => $confirm],
            [
                'username' => ['required', 'string', 'max:50', 'unique:users,username'],
                'email' => ['required', 'email:rfc', 'max:150', 'unique:users,email'],
                // The one system-wide policy (AppServiceProvider).
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->option('name') ?: $this->argument('username'),
            'username' => $this->argument('username'),
            'email' => $this->argument('email'),
            // Hashed by the model's "hashed" cast.
            'password' => $password,
            'user_type' => 'admin',
            'is_active' => true,
            'can_login' => true,
            // The administrator typed this password themselves: nothing to change at first sign-in.
            'must_change_password' => false,
        ]);
        $user->assignRole('Super Admin');

        $this->info("Super Admin {$user->username} created.");

        return self::SUCCESS;
    }
}
