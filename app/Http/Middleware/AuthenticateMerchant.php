<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the calling merchant (tenant) from its API key and attaches it to the request.
 * Everything downstream (validation, rate limiting, queries) is scoped to this merchant,
 * so one tenant can never write usage for another tenant's customers.
 */
class AuthenticateMerchant
{
    public const ATTRIBUTE = 'merchant';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken() ?? $request->header('X-Api-Key');

        $merchant = is_string($key) && $key !== '' ? Merchant::findByApiKey($key) : null;

        if ($merchant === null) {
            return response()->json(['message' => 'Invalid or missing API key.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set(self::ATTRIBUTE, $merchant);

        return $next($request);
    }
}
