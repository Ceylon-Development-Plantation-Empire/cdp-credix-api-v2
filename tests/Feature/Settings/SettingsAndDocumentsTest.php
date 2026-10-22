<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\DocumentRequirement;
use App\Models\Setting;
use Database\Seeders\DocumentRequirementSeeder;
use Database\Seeders\SettingsSeeder;

describe('settings', function () {
    beforeEach(fn () => $this->seed(SettingsSeeder::class));

    it('validates a new value with the rules stored on the setting', function () {
        $this->actingAs(staffWith('Setting Update'), 'api')
            ->putJson('/api/v1/settings/guarantor.max_active_guarantees', ['value' => 0])
            ->assertStatus(422);

        $this->actingAs(staffWith('Setting Update'), 'api')
            ->putJson('/api/v1/settings/penalty.calculation', ['value' => 'percent_of_principal'])
            ->assertStatus(422);
    });

    it('saves, casts and audits a valid change', function () {
        $this->actingAs(staffWith('Setting Update'), 'api')
            ->putJson('/api/v1/settings/guarantor.max_active_guarantees', ['value' => 3])
            ->assertOk()
            ->assertJsonPath('data.value', 3);

        expect(Setting::value('guarantor.max_active_guarantees'))->toBe(3)
            ->and(ActivityLog::where('module', 'Setting')->exists())->toBeTrue();
    });

    it('keeps json settings to the allowed components', function () {
        $this->actingAs(staffWith('Setting Update'), 'api')
            ->putJson('/api/v1/settings/allocation.order.conventional', ['value' => ['penalty', 'penalty', 'profit', 'principal']])
            ->assertStatus(422);

        expect(Setting::value('allocation.order.islamic'))->toBe(['profit', 'principal', 'charge', 'penalty']);
    });

    it('returns typed values with defaults for unknown keys', function () {
        expect(Setting::value('recovery.notice_dpd'))->toBe(90)
            ->and(Setting::value('guarantor.block_after_invocation'))->toBeTrue()
            ->and(Setting::value('penalty.value'))->toBe('2')
            ->and(Setting::value('does.not.exist', 'fallback'))->toBe('fallback');
    });
});

describe('document rules', function () {
    beforeEach(fn () => $this->seed(DocumentRequirementSeeder::class));

    it('seeds the customer and guarantor document rules', function () {
        $identity = DocumentRequirement::with('documentType')
            ->where('party_role', 'borrower')->where('requirement_group', 'identity-proof')->get();
        $employed = DocumentRequirement::where('party_role', 'guarantor')->where('employment_status', 'employed')->get();

        expect($identity->pluck('documentType.slug')->all())->toBe(['nic-copy', 'passport-copy', 'driving-licence-copy'])
            ->and($identity->pluck('rule')->unique()->map->value->all())->toBe(['any'])
            ->and($employed)->toHaveCount(3)
            ->and(DocumentRequirement::where('party_role', 'guarantor')->where('requirement_group', 'residency-proof')->exists())->toBeFalse()
            ->and(DocumentRequirement::where('party_role', 'borrower')->where('employment_status', 'self_employed')->value('min_count'))->toBe(6);
    });

    it('refuses mixing any and all inside one requirement group', function () {
        $this->actingAs(staffWith('Document Requirement Create'), 'api')
            ->postJson('/api/v1/document-requirements', [
                'party_role' => 'borrower',
                'requirement_group' => 'identity-proof',
                'rule' => 'all',
                'document_type_slug' => 'customer-photo',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_REQUIREMENT_RULE_CONFLICT');
    });
});
