<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(PermissionsSeeder::class);

        // The development admin has a well-known password: never on a production database.
        // There, create the first administrator with: php artisan credix:create-admin
        if (! app()->environment('production')) {
            $this->call(UserSeeder::class);
        }

        $this->call(SettingsSeeder::class);
        $this->call(ProductCatalogSeeder::class);
        $this->call(DocumentRequirementSeeder::class);
        $this->call(TemplateSeeder::class);
    }
}
