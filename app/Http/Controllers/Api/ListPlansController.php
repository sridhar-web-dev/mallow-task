<?php

namespace App\Http\Controllers\Api;

use App\Data\PlanData;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMerchant;
use App\Services\PlanCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListPlansController extends Controller
{
    public function __invoke(Request $request, PlanCatalog $catalog): JsonResponse
    {
        $merchant = $request->attributes->get(AuthenticateMerchant::ATTRIBUTE);

        $plans = $catalog->forMerchant($merchant->id)
            ->filter(fn (PlanData $plan) => $plan->isActive)
            ->map(fn (PlanData $plan) => $plan->toArray())
            ->values();

        return response()->json(['data' => $plans]);
    }
}
