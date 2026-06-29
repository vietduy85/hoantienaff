<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinkRequest extends Model
{
    protected $fillable = [
        'user_id',
        'original_url',
        'platform',
        'affiliate_url',
        'estimated_cashback',
        'status',
        'notes',
        'is_pinned',
        'pinned_at',
        'item_id',
        'shop_id',
        'product_name',
        'product_price',
        'product_link',
        'user_estimated_cashback',
        'cashback_rate',
        'seller_commission',
        'shopee_commission',
        'rating',
        'is_xtra',
        'product_image',
        'shop_name',
        'sales',
        'data_source',
    ];

    protected function casts(): array
    {
        return [
            'estimated_cashback' => 'decimal:2',
            'user_estimated_cashback' => 'decimal:2',
            'cashback_rate' => 'decimal:2',
            'is_pinned' => 'boolean',
            'pinned_at' => 'datetime',
            'is_xtra' => 'boolean',
            'rating' => 'decimal:2',
        ];
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function getShortUrlAttribute(): string
    {
        $url = $this->affiliate_url;
        if (!$url) {
            return '';
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = preg_replace('/^www\./', '', $host);

        $code = substr(md5($url), 0, 8);

        return $host . '/' . $code;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
