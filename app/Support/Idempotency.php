<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\BusinessRuleException;
use App\Finance\Support\Money;
use App\Models\LoanTransaction;

/**
 * Replays of money-moving requests (Idempotency-Key).
 *
 * SECURITY: the same key returns the first result instead of moving money twice. The same key
 * with a DIFFERENT amount is refused: silently returning the first transaction would let a
 * caller believe a different payment was posted.
 */
final class Idempotency
{
    /**
     * The transaction already posted under this key, or null when the key is new.
     *
     * @param  string  $keyHash  The service's loan-scoped hash of the Idempotency-Key header.
     * @param  string|null  $amount  The amount of the incoming request (null when the request has none).
     *
     * @throws BusinessRuleException IDEMPOTENCY_KEY_REUSED (422)
     */
    public static function replay(string $keyHash, ?string $amount): ?LoanTransaction
    {
        $existing = LoanTransaction::query()->where('idempotency_key', $keyHash)->first();

        if ($existing && $amount !== null && ! Money::of($existing->amount)->isEqualTo(Money::of($amount))) {
            throw new BusinessRuleException(
                'IDEMPOTENCY_KEY_REUSED',
                'This Idempotency-Key was already used for a different amount. Use a new key for a new request.',
                422,
            );
        }

        return $existing;
    }
}
