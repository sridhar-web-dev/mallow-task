<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The idempotency key was already used for a different payload. Silently returning the
 * original event would hide a client bug, and overwriting it would break idempotency,
 * so the request is rejected (the same approach Stripe takes).
 */
class IdempotencyKeyConflict extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey)
    {
        parent::__construct("Idempotency key [{$idempotencyKey}] was already used with a different payload.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], Response::HTTP_CONFLICT);
    }
}
