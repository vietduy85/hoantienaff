<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'click_id',
        'campaign_id',
        'member_id',
        'affiliate_id',
        'order_id',
        'order_amount',
        'cashback_amount',
        'commission_amount',
        'affiliate_commission',
        'status',
        'confirmed_at',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'order_amount' => 'decimal:2',
            'cashback_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'affiliate_commission' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeCancelled($query)
    {
        return $query->whereIn('status', ['cancelled', 'refunded']);
    }

    public function click(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Click::class);
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function member(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function affiliate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }
}
