<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\SettingType;
use App\Exceptions\BusinessRuleException;
use App\Models\Setting;
use App\Models\User;
use App\Traits\ActivityLogTrait;
use Illuminate\Support\Facades\Validator;

class SettingService
{
    use ActivityLogTrait;

    /**
     * Add a custom setting. Its value rules follow from its type.
     *
     * @param  array{key: string, group: string, type: string, value: mixed, label: string, description?: string|null}  $data
     */
    public function create(array $data, User $user): Setting
    {
        $type = SettingType::from($data['type']);
        $rules = ['value' => match ($type) {
            SettingType::Integer => 'required|integer',
            SettingType::Decimal => 'required|numeric',
            SettingType::Boolean => 'required|boolean',
            SettingType::String => 'required|string|max:1000',
            SettingType::Json => 'required|array',
        }];

        Validator::make(['value' => $data['value']], $rules)->validate();

        $setting = new Setting([
            'key' => $data['key'],
            'group' => $data['group'],
            'type' => $type,
            'value' => $type->serialize($data['value']),
            'rules' => $rules,
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'updated_by' => $user->id,
        ]);
        $setting->forceFill(['is_custom' => true])->save();

        $this->logActivity('CREATE', 'Setting', "Created setting: {$setting->key}", ['key' => $setting->key, 'value' => $setting->typedValue()]);

        return $setting;
    }

    /**
     * Delete a custom setting. The settings the code reads by name cannot be deleted.
     */
    public function delete(Setting $setting): void
    {
        if (! $setting->is_custom) {
            throw new BusinessRuleException('SETTING_IS_SYSTEM', 'This setting is read by the system and cannot be deleted.', 409);
        }

        $key = $setting->key;
        $setting->delete();

        $this->logActivity('DELETE', 'Setting', "Deleted setting: {$key}", ['key' => $key]);
    }

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
