<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Services\Dashboard\MerchantDashboardService;
use Illuminate\Http\JsonResponse;

class MerchantDashboardController extends Controller
{
    public function __construct(private MerchantDashboardService $service)
    {
    }

    public function show(int $merchantId): JsonResponse
    {
        Merchant::findOrFail($merchantId); // 404 if merchant doesn't exist

        return response()->json([
            'data' => $this->service->build($merchantId),
        ]);
    }
}