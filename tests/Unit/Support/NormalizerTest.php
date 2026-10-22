<?php

declare(strict_types=1);

use App\Support\NicNormalizer;
use App\Support\PhoneNormalizer;

describe('NIC', function () {
    it('turns an old NIC into the 12 digit form', function () {
        expect(NicNormalizer::normalize('853400937V'))->toBe('198534000937')
            ->and(NicNormalizer::normalize(' 853400937v '))->toBe('198534000937')
            ->and(NicNormalizer::normalize('198534000937'))->toBe('198534000937');
    });

    it('reads date of birth and gender', function () {
        // Day 340 on the NIC calendar (February always has 29 days) is 5 December.
        expect(NicNormalizer::birthDate('853400937V')->toDateString())->toBe('1985-12-05')
            ->and(NicNormalizer::gender('853400937V'))->toBe('male')
            ->and(NicNormalizer::gender('858400937V'))->toBe('female');
    });

    it('rejects malformed numbers', function (string $nic) {
        expect(NicNormalizer::normalize($nic))->toBeNull();
    })->with(['853700937V', '85340093V', 'ABCDEFGHIV', '1985340009371', '198500000937']);
});

describe('phone', function () {
    it('stores every format as E.164', function (string $input) {
        expect(PhoneNormalizer::normalize($input))->toBe('+94771234567');
    })->with(['0771234567', '771234567', '+94 77 123 4567', '94771234567', '0094771234567']);

    it('knows mobiles from landlines and masks numbers', function () {
        expect(PhoneNormalizer::isMobile('0771234567'))->toBeTrue()
            ->and(PhoneNormalizer::isMobile('0112345678'))->toBeFalse()
            ->and(PhoneNormalizer::normalize('12345'))->toBeNull()
            ->and(PhoneNormalizer::mask('+94771234567'))->toBe('+9477****567');
    });
});
