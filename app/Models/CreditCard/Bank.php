<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank — ngân hàng phát hành thẻ. Master data, user KHÔNG tự tạo/sửa.
 *
 * MỘT bank = MỘT pháp nhân. Mã cũ/alias KHÔNG được tạo thành bank thứ hai mà
 * lưu vào cột `aliases` (xem migration 000012 + `Bank::resolveByCode()`).
 *
 * @property int $id
 * @property string $name
 * @property string|null $short_name
 * @property string $slug
 * @property string|null $logo
 * @property bool $is_active
 * @property array<int, string> $aliases Mã legacy/alias, ví dụ Sacombank => ['scb']
 */
class Bank extends CreditCardModel
{
    protected $table = 'credit_card_banks';

    protected $fillable = [
        'name',
        'short_name',
        'slug',
        'logo',
        'is_active',
        'aliases',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'aliases' => 'array',
        ];
    }

    /**
     * Sản phẩm thẻ do bank này phát hành.
     *
     * CHỈ còn để tương thích catalog Phase 1A — luồng chính KHÔNG dùng
     * (xem migration 000011).
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'bank_id');
    }

    /**
     * Thẻ tín dụng của user thuộc bank này (FK trực tiếp `bank_id`).
     */
    public function userCards(): HasMany
    {
        return $this->hasMany(UserCard::class, 'bank_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $keyword): Builder
    {
        if ($keyword === null || trim($keyword) === '') {
            return $query;
        }

        $keyword = trim($keyword);

        return $query->where(function (Builder $inner) use ($keyword): void {
            $inner->where('name', 'like', '%'.$keyword.'%')
                ->orWhere('short_name', 'like', '%'.$keyword.'%')
                // Cho phép tìm bằng mã alias, ví dụ gõ "scb" ra Sacombank.
                ->orWhere('aliases', 'like', '%'.$keyword.'%');
        });
    }

    /**
     * Bank này có nhận mã `code` nào không (canonical slug hoặc alias)?
     */
    public function matchesCode(string $code): bool
    {
        $code = strtolower(trim($code));

        if ($code === '') {
            return false;
        }

        if ($code === strtolower($this->slug)) {
            return true;
        }

        if ($code === strtolower((string) $this->short_name)) {
            return true;
        }

        foreach ((array) $this->aliases as $alias) {
            if ($code === strtolower(trim((string) $alias))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tra bank theo mã: ưu tiên slug canonical, sau đó mới tới alias.
     *
     * Dùng khi import dữ liệu cũ (mã "SCB") để không tạo bank mới. Quét 43 dòng
     * trong bộ nhớ là rẻ hơn nhiều so với thêm index JSON trên MySQL 5.7.
     */
    public static function resolveByCode(string $code, bool $activeOnly = true): ?self
    {
        $code = strtolower(trim($code));

        if ($code === '') {
            return null;
        }

        $query = static::query();

        if ($activeOnly) {
            $query->active();
        }

        return $query->get()->first(fn (self $bank): bool => $bank->matchesCode($code));
    }
}
