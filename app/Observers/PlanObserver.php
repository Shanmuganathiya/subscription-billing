<?php

namespace App\Observers;

use App\Models\Plan;
use App\Services\Billing\PlanRepository;

class PlanObserver
{
    public function __construct(private PlanRepository $planRepository)
    {
    }

    public function saved(Plan $plan): void
    {
        $this->planRepository->forget($plan->id);
    }

    public function deleted(Plan $plan): void
    {
        $this->planRepository->forget($plan->id);
    }
}