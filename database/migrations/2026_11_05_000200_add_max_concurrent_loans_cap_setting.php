<?php

declare(strict_types=1);

use App\Enums\SettingType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'loan.max_concurrent_loans_cap'],
            [
                'group' => 'loans',
                'type' => SettingType::Integer->value,
                'value' => '10',
                'rules' => json_encode(['value' => 'required|integer|min:2|max:50']),
                'label' => 'Maximum concurrent loans cap for special cases',
                'description' => 'The dynamic upper limit for how many concurrent open loans an authorized customer can hold (e.g. 5, 10, 15). Controls the maximum option available when granting special case exceptions.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'loan.max_concurrent_loans_cap')->delete();
    }
};

