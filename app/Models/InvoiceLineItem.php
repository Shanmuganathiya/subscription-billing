<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLineItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'subscription_id', 'segment_start', 'segment_end',
        'included_units', 'used_units', 'overage_units',
        'prorated_base', 'overage_charge',
    ];

    protected $casts = [
        'segment_start' => 'date',
        'segment_end' => 'date',
        'prorated_base' => 'decimal:2',
        'overage_charge' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}