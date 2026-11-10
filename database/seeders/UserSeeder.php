<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'dev@localhost.com', 'username' => 'devadmin'],
            [
                'name' => 'Development Admin',
                'password' => 'password',
                'must_change_password' => false,
                'is_active' => true,
                'can_login' => true,
            ]
        );

        $user->assignRole('Super Admin');

        $this->command->info('Development admin user created!');
        $this->command->info('Email: dev@localhost.com');
        $this->command->info('Password: password');
    }
}
