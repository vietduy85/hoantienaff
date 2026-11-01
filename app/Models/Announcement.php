<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Thông báo ngắn trên trang chủ hoàn tiền (ngay trên dòng mời chọn thẻ tín dụng).
 * Admin tạo ở menu "Thông báo trang chủ"; link đi qua route announcements.click để đếm lượt bấm.
 */
class Announcement extends Model
{
    /** Vị trí chữ link trong nội dung; không có thì chữ link nằm cuối câu. */
    public const LINK_PLACEHOLDER = '{link}';

    public const DEFAULT_LINK_TEXT = 'tại đây';

    /** Số thông báo tối đa hiện cùng lúc trên trang chủ. */
    public const MAX_VISIBLE = 3;

    /**
     * Bảng màu chọn sẵn. Class Tailwind viết nguyên chuỗi ở đây (file này nằm trong
     * content của tailwind.config.js) để không bị purge.
     */
    public const COLORS = [
        'amber' => ['label' => 'Cam (nổi bật)', 'box' => 'bg-amber-50 border-amber-200 text-amber-900', 'link' => 'text-amber-700'],
        'rose' => ['label' => 'Đỏ (gấp)', 'box' => 'bg-rose-50 border-rose-200 text-rose-900', 'link' => 'text-rose-700'],
        'emerald' => ['label' => 'Xanh lá', 'box' => 'bg-emerald-50 border-emerald-200 text-emerald-900', 'link' => 'text-emerald-700'],
        'sky' => ['label' => 'Xanh dương', 'box' => 'bg-sky-50 border-sky-200 text-sky-900', 'link' => 'text-sky-700'],
        'violet' => ['label' => 'Tím', 'box' => 'bg-violet-50 border-violet-200 text-violet-900', 'link' => 'text-violet-700'],
    ];

    protected $fillable = [
        'content',
        'link_text',
        'url',
        'open_new_tab',
        'icon',
        'color',
        'is_active',
        'sort_order',
        'start_at',
        'end_at',
    ];

    protected function casts(): array
    {
        return [
            'open_new_tab' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'click_count' => 'integer',
        ];
    }

    /** Đang bật và nằm trong khung thời gian hiển thị. */
    public function scopeCurrent(Builder $query, ?Carbon $now = null): Builder
    {
        $now ??= Carbon::now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('start_at')->orWhere('start_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('end_at')->orWhere('end_at', '>=', $now));
    }

    /** Các thông báo cần hiện trên trang chủ, theo thứ tự admin đặt. */
    public static function forHome(): \Illuminate\Support\Collection
    {
        return static::query()->current()
            ->orderBy('sort_order')->orderByDesc('id')
            ->limit(self::MAX_VISIBLE)
            ->get();
    }

    public function colorClasses(): array
    {
        return self::COLORS[$this->color] ?? self::COLORS['amber'];
    }

    public function linkLabel(): string
    {
        return filled($this->link_text) ? $this->link_text : self::DEFAULT_LINK_TEXT;
    }

    /**
     * Tách nội dung quanh {link}: [trước, sau]. Không có {link} thì chữ link nằm cuối.
     *
     * @return array{0: string, 1: string}
     */
    public function contentParts(): array
    {
        $parts = explode(self::LINK_PLACEHOLDER, $this->content, 2);

        if (count($parts) === 1) {
            return [rtrim($parts[0]).' ', ''];
        }

        // Nếu không có URL mà vẫn có {link}, các {link} thừa cũng bỏ đi.
        return [$parts[0], str_replace(self::LINK_PLACEHOLDER, '', $parts[1])];
    }

    /** Chữ thuần khi không có URL (bỏ {link}). */
    public function plainContent(): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(self::LINK_PLACEHOLDER, '', $this->content)));
    }

    /** Khoá localStorage để user ẩn thông báo; đổi khi admin sửa → hiện lại. */
    public function dismissKey(): string
    {
        return 'ann-hide-'.$this->id.'-'.($this->updated_at?->timestamp ?? 0);
    }

    public function statusLabel(?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        return match (true) {
            ! $this->is_active => 'Đang tắt',
            $this->start_at !== null && $this->start_at->gt($now) => 'Chờ tới giờ',
            $this->end_at !== null && $this->end_at->lt($now) => 'Đã hết hạn',
            default => 'Đang hiện',
        };
    }
}
