<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Click extends Model
{
    protected $fillable = [
        'campaign_id',
        'affiliate_id',
        'member_id',
        'ip_address',
        'user_agent',
        'referrer_url',
        'clicked_at',
        'converted',
    ];

    protected function casts(): array
    {
        return [
            'clicked_at' => 'datetime',
            'converted' => 'boolean',
        ];
    }

    public function scopeConverted($query)
    {
        return $query->where('converted', true);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('clicked_at', '>=', now()->subDays($days));
    }

    public function campaign(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function affiliate(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'affiliate_id');
    }

    public function member(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function purchase(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Purchase::class);
    }
}
