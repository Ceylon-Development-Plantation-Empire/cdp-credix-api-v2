<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('lookups')->where('type', 'collateral_type')->where('code', 'property_mortgage')->first();
        if ($row) {
            $meta = json_decode((string) $row->meta, true) ?: [];
            if (! isset($meta['fields']['property_type'])) {
                // Insert property_type right after deed_no
                $fields = [];
                foreach ($meta['fields'] as $key => $rules) {
                    $fields[$key] = $rules;
                    if ($key === 'deed_no') {
                        $fields['property_type'] = ['nullable', 'string', 'max:50'];
                    }
                }
                if (! isset($fields['property_type'])) {
                    $fields['property_type'] = ['nullable', 'string', 'max:50'];
                }
                $meta['fields'] = $fields;
                DB::table('lookups')->where('id', $row->id)->update(['meta' => json_encode($meta), 'updated_at' => now()]);
            }
        }

        Cache::forget('lookups:v2:collateral_type');
    }

    public function down(): void
    {
        $row = DB::table('lookups')->where('type', 'collateral_type')->where('code', 'property_mortgage')->first();
        if ($row) {
            $meta = json_decode((string) $row->meta, true) ?: [];
            if (isset($meta['fields']['property_type'])) {
                unset($meta['fields']['property_type']);
                DB::table('lookups')->where('id', $row->id)->update(['meta' => json_encode($meta), 'updated_at' => now()]);
            }
        }

        Cache::forget('lookups:v2:collateral_type');
    }
};

