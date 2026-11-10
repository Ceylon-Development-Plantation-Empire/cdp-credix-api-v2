<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'otp.expiry_minutes')->update([
            'value' => '1',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'otp.expiry_minutes')->update([
            'value' => '5',
            'updated_at' => now(),
        ]);
    }
};

