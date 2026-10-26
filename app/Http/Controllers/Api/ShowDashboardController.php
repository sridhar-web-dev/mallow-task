<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMerchant;
use App\Services\MerchantDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowDashboardController extends Controller
{
    public function __invoke(Request $request, int $merchantId, MerchantDashboard $dashboard): JsonResponse
    {
        $merchant = $request->attributes->get(AuthenticateMerchant::ATTRIBUTE);

        // A merchant may only see its own dashboard. Any other id, whether it exists or not,
        // gets the same 403, so the endpoint cannot be used to discover which merchant ids exist.
        abort_unless($merchant->id === $merchantId, 403);

        return response()->json(['data' => $dashboard->build($merchant, CarbonImmutable::today())]);
    }
}
