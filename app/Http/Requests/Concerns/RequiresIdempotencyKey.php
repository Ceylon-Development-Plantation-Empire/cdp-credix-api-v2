<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * The Idempotency-Key header of a money-moving request (payments, disbursement, settlement,
 * gold renewal, recoveries), validated like a body field.
 *
 * SECURITY: the key makes a retried request (double click, network retry, page refresh) return
 * the first result instead of moving money twice. 8–64 characters from a safe alphabet, so it can
 * be hashed and stored as a unique key; the services scope it to the loan.
 */
trait RequiresIdempotencyKey
{
    /**
     * Copy the header into the validated data.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * The rule for the copied header.
     *
     * @return array{idempotency_key: list<string>}
     */
    protected function idempotencyKeyRules(): array
    {
        return ['idempotency_key' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/']];
    }

    /**
     * The documented message when the header is missing.
     *
     * @param  string  $what  e.g. "payments", "disbursements"
     * @return array{'idempotency_key.required': string}
     */
    protected function idempotencyKeyMessage(string $what): array
    {
        return ['idempotency_key.required' => "The Idempotency-Key header is required for {$what}."];
    }
}
