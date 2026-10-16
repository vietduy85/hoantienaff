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
 * LƯU Ý về các campaign AccessTrade:
 *  - Tiki KHÔNG được thêm rule vì `tiki.vn` đã bị rule code cứng nhận diện
 *    (PlatformResolver → STRATEGY_UNSUPPORTED); overlay DB chạy sau nên rule sẽ
 *    là code chết. Muốn đổi phải sửa code (ngoài phạm vi, không được phép).
 *  - Nature Foods Shopee PUB có URL https://shopee.vn/naturefoods_vn → host là
 *    shopee.vn (generic) và campaign chưa được duyệt → không thêm rule.
 *  - FPT Shop / Thế Giới Di Động / Fado.vn hiện `approval=unregistered` với
 *    token này → không dùng ID chưa duyệt → không thêm rule.
 *  - Adidas Việt Nam Online có URL chiến dịch là link tracking
 *    https://adidasvn.pxf.io/... → pattern là host tracking (không phải host
 *    sản phẩm); URL sản phẩm thật của Adidas KHÔNG suy ra được từ API và không
 *    được tự đoán. Rule được tạo đúng theo URL chiến dịch thật và cần admin bổ
 *    sung host sản phẩm khi biết.
 *
 * Chạy:
 *   php artisan db:seed --class=AffiliateDetectRuleSeeder
 */
class AffiliateDetectRuleSeeder extends Seeder
{
    /**
     * Rule cần bảo đảm tồn tại. campaign_id lấy từ GET
     * /v1/campaigns?approval=successful (22 campaign đã duyệt) và
     * GET /v1/campaigns?campaign_id=... (chi tiết campaign).
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
        [
            'name' => 'Điện Thoại Vui',
            'pattern' => 'dienthoaivui.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6685985692750012237',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Điện Thoại Vui (dienthoaivui), campaign 6685985692750012237.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'BỀN COMPUTER',
            'pattern' => 'ben.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6544141033462679814',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — BỀN COMPUTER (ben), campaign 6544141033462679814.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Shondo',
            'pattern' => 'shondoshoes.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6775153232642032998',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Shondo (shondo_web), campaign 6775153232642032998.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Hoàng Hà Mobile',
            'pattern' => 'hoanghamobile.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5229340396064683522',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Hoàng Hà Mobile (hoanghamobile), campaign 5229340396064683522.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Adidas Việt Nam Online',
            'pattern' => 'adidasvn.pxf.io',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5493145244551778830',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Adidas Việt Nam Online (adidas_vietnam), campaign 5493145244551778830. URL chiến dịch là link tracking adidasvn.pxf.io; host sản phẩm thật cần admin bổ sung.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Con Cưng',
            'pattern' => 'concung.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5204532880919025215',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Con Cưng (concung), campaign 5204532880919025215.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Lug',
            'pattern' => 'lug.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5199030632523966380',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Lug (lug), campaign 5199030632523966380.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'MediaMart',
            'pattern' => 'mediamart.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6009072433920808367',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — MediaMart (mediamart), campaign 6009072433920808367.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => "Biti's",
            'pattern' => 'bitis.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5320689651876146371',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => "Merchant AccessTrade — Biti's (bitis.myharavan.com), campaign 5320689651876146371.",
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
