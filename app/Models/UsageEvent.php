<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageEvent extends Model
{
    use HasFactory;

    public $timestamps = false; // we manage created_at manually via useCurrent()

    protected $fillable = [
        'customer_id', 'merchant_id', 'occurred_on', 'units', 'idempotency_key',
    ];

    protected $casts = [
        'occurred_on' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}