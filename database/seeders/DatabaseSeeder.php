<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * php artisan db:seed / migrate:fresh --seed.
 *
 * 1. System reference data (every environment): see SystemDataSeeder.
 * 2. Demo / development data (never in production): the development admin with a well-known
 *    password and the placeholder product catalogue. In production create the first administrator
 *    with `php artisan credix:create-admin` and set up real products in the app.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SystemDataSeeder::class);

        if (! app()->environment('production')) {
            $this->call([
                UserSeeder::class,
                ProductCatalogSeeder::class,
            ]);
        }
    }
}
