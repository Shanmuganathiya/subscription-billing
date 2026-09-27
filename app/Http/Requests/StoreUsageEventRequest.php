<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUsageEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // add auth/API-key check later if you wire up Sanctum
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'occurred_on' => ['required', 'date'],
            'units' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ];
    }
}