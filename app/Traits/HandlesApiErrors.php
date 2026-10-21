<?php

namespace App\Traits;

use App\Exceptions\BusinessRuleException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

trait HandlesApiErrors
{
    /**
     * The catch block of every controller action, in the starter kit's error format.
     *
     * Expected errors are thrown on so Laravel renders them with their own status
     * (business rule 409/422, validation 422). Anything else is logged with the full exception
     * and the client only gets a generic message unless APP_DEBUG is on.
     */
    protected function serverError(string $message, \Throwable $th): JsonResponse
    {
        if ($th instanceof BusinessRuleException || $th instanceof ValidationException || $th instanceof HttpResponseException) {
            throw $th;
        }

        report($th);

        return response()->json([
            'status' => 'error',
            'message' => $message,
            'error' => config('app.debug') ? $th->getMessage() : 'Internal server error',
        ], 500);
    }

    protected function notFound(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
        ], 404);
    }
}
