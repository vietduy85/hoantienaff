<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'referral_code',
        'referred_by',
        'wallet_balance',
        'total_earned',
        'total_withdrawn',
        'phone',
        'avatar',
        'google_id',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_balance' => 'decimal:2',
            'total_earned' => 'decimal:2',
            'total_withdrawn' => 'decimal:2',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (User $user) {
            if (empty($user->referral_code)) {
                $user->referral_code = static::generateReferralCode();
            }
        });
    }

    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    public function merchant(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Merchant::class);
    }

    public function referredBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(__CLASS__, 'referred_by');
    }

    public function referredUsers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(__CLASS__, 'referred_by');
    }

    public function affiliateClicks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Click::class, 'affiliate_id');
    }

    public function memberClicks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Click::class, 'member_id');
    }

    public function purchases(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Purchase::class, 'member_id');
    }

    public function affiliatePurchases(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Purchase::class, 'affiliate_id');
    }

    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function withdrawals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function processedWithdrawals(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Withdrawal::class, 'processed_by');
    }

    public function linkRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LinkRequest::class);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('Admin');
    }

    public function isMerchant(): bool
    {
        return $this->hasRole('Merchant');
    }

    public function isAffiliate(): bool
    {
        return $this->hasRole('Affiliate');
    }

    public function isMember(): bool
    {
        return $this->hasRole('Member');
    }
}
