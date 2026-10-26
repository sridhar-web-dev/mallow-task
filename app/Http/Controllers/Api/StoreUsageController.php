<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordUsageAction;
use App\Data\UsageEventData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUsageRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class StoreUsageController extends Controller
{
    public function __invoke(StoreUsageRequest $request, RecordUsageAction $recordUsage): JsonResponse
    {
        $recorded = $recordUsage->execute(UsageEventData::fromRequest($request));

        // 201 for a new event, 200 for a replayed key, so clients can tell the two apart
        // without parsing the body.
        return response()->json(
            ['data' => $recorded->toArray()],
            $recorded->duplicate ? Response::HTTP_OK : Response::HTTP_CREATED,
        );
    }
}
