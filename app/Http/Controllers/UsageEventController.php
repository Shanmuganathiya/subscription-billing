<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUsageEventRequest;
use App\Services\UsageEventService;
use Illuminate\Http\JsonResponse;

class UsageEventController extends Controller
{
    public function __construct(private UsageEventService $service)
    {
    }

    public function store(StoreUsageEventRequest $request): JsonResponse
    {
        $event = $this->service->record($request->validated());

        return response()->json(['data' => $event], 201);
    }
}