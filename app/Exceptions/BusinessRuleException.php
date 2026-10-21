<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown by services when a request is valid in shape but breaks a business rule
 * ("this customer already has an open loan", "guarantor limit reached" …).
 *
 * The code is a stable, machine-readable key the frontend can switch on; the message
 * is safe to show to the user. Laravel calls render() automatically, so controllers
 * do not need to catch it.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  Extra safe details for the client (never internal data).
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'status' => 'error',
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'details' => $this->context ?: null,
        ]), $this->status);
    }
}
