<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Everything the application needs to run correctly in EVERY environment (production included):
 * permissions, code lists, fee types, the system GL accounts, settings and message templates.
 *
 * All of these seeders are idempotent and never overwrite values changed by the business, so this
 * is safe to run on every deploy: php artisan db:seed --class=SystemDataSeeder --force
 *
 * Demo / development data (the development admin, the placeholder product catalogue and document
 * rules) lives in DatabaseSeeder, outside production.
 */
class SystemDataSeeder extends Seeder
{
    /**
     * Seed the system reference data.
     */
    public function run(): void
    {
        $this->call([
            PermissionsSeeder::class,
            ReferenceDataSeeder::class,
            // Document types are referenced by code (collateral and disbursement checks); the
            // requirement rules are the agreed starting point the business then edits in the app.
            DocumentRequirementSeeder::class,
            SettingsSeeder::class,
            TemplateSeeder::class,
        ]);
    }
}
