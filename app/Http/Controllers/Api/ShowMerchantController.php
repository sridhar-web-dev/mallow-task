<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMerchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Who am I" for an API key, so a client holding only a key (such as the dashboard page)
 * can discover its merchant id.
 */
class ShowMerchantController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $merchant = $request->attributes->get(AuthenticateMerchant::ATTRIBUTE);

        return response()->json(['data' => $merchant->only(['id', 'name', 'slug', 'currency'])]);
    }
}
