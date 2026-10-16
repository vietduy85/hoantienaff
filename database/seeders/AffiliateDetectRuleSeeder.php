<?php

namespace Database\Seeders;

use App\Models\AffiliateDetectRule;
use App\Services\Url\AffiliateDetectRuleRepository;
use Illuminate\Database\Seeder;

/**
 * AffiliateDetectRuleSeeder — nạp rule "Chuỗi nhận diện URL" do admin quản lý.
 *
 * IDEMPOTENT và KHÔNG GHI ĐÈ: seeder chỉ TẠO rule khi chưa tồn tại rule cùng
 * cặp (pattern, match_type). Nếu rule đã có (kể cả admin đã sửa), seeder BỎ QUA
 * để không làm mất thay đổi thủ công. Không xoá/không sửa rule nào khác.
 *
 * Cơ chế hiện tại: đây là overlay additive chạy SAU các rule code cứng
 * (Shopee/ShopeeFood/Lazada/TikTok/Tiki), nên chỉ áp dụng cho URL chưa được
 * nhận diện bởi code — ví dụ merchant AccessTrade như CellphoneS.
 *
 * Chạy:
 *   php artisan db:seed --class=AffiliateDetectRuleSeeder
 */
class AffiliateDetectRuleSeeder extends Seeder
{
    /**
     * Rule cần bảo đảm tồn tại. campaign_id đã được xác minh live
     * (POST /v1/product_link/create → HTTP 200) cho merchant cellphones_cps.
     *
     * @var array<int, array<string, mixed>>
     */
    private const RULES = [
        [
            'name' => 'CellphoneS',
            'pattern' => 'cellphones.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6259155740535091857',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — CellphoneS (cellphones_cps), campaign 6259155740535091857.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
    ];

    public function run(): void
    {
        $created = 0;
        $skipped = 0;

        foreach (self::RULES as $rule) {
            $exists = AffiliateDetectRule::query()
                ->where('pattern', $rule['pattern'])
                ->where('match_type', $rule['match_type'])
                ->exists();

            if ($exists) {
                $skipped++;
                $this->command?->info(sprintf('Bỏ qua (đã tồn tại): %s [%s]', $rule['name'], $rule['pattern']));

                continue;
            }

            AffiliateDetectRule::create($rule);
            $created++;
            $this->command?->info(sprintf('Đã tạo: %s [%s]', $rule['name'], $rule['pattern']));
        }

        app(AffiliateDetectRuleRepository::class)->flush();

        $this->command?->info(sprintf('Hoàn tất: tạo %d, bỏ qua %d. Đã làm mới cache rule.', $created, $skipped));
    }
}
