<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Facades\Validator;

class SettingService
{
    use ActivityLogTrait;

    /**
     * Change a setting after validating the new value against the rules stored on the row.
     *
     * The rules live in the database next to the value ({"value": "required|integer|min:0"}),
     * so every setting validates itself and no setting can be saved in a shape the code does
     * not expect. Throws a ValidationException (HTTP 422) when the value is not acceptable.
     */
    public function update(Setting $setting, mixed $value, User $user): Setting
    {
        Validator::make(['value' => $value], $setting->rules)->validate();

        $oldValue = $setting->typedValue();

        $setting->update([
            'value' => $setting->type->serialize($value),
            'updated_by' => $user->id,
        ]);

        // Settings change how money is calculated, so the old and new values are always audited.
        $this->logActivity('UPDATE', 'Setting', "Updated setting: {$setting->key}", [
            'key' => $setting->key,
            'old_value' => $oldValue,
            'new_value' => $setting->typedValue(),
        ]);

        return $setting;
    }
}
