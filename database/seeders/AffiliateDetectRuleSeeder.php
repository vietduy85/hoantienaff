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
     * /v1/campaigns?approval=successful và GET /v1/campaigns?campaign_id=...
     * (chi tiết campaign). Chỉ thêm rule cho campaign có approval=successful.
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
        [
            'name' => 'VINAMILK WEB',
            'pattern' => 'vinamilk.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '7029539453233564544',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — VINAMILK WEB (vinamilkcps_web), campaign 7029539453233564544.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'ALDO WEB',
            'pattern' => 'aldoshoes.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '7028102478473631427',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — ALDO WEB (aldo_web), campaign 7028102478473631427.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Wilson',
            'pattern' => 'wilsonsports.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6985534491068802784',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Wilson (wilson), campaign 6985534491068802784.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'ANTA KIDS WEBSITE',
            'pattern' => 'antakids.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6985531971150284313',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — ANTA KIDS (anta_kids), campaign 6985531971150284313.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'ANTA Affiliate',
            'pattern' => 'anta.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6979658268650552691',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — ANTA (anta), campaign 6979658268650552691.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'KINGFOODMART',
            'pattern' => 'kingfoodmart.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6957824399262393441',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — KINGFOODMART (kingfoodmart), campaign 6957824399262393441.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'ALIBABA',
            'pattern' => 'alibaba.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6954336422318207062',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — ALIBABA (admitad_alibaba), campaign 6954336422318207062.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Aristino Web',
            'pattern' => 'aristino.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6568081282047528536',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Aristino (aristino_haravan), campaign 6568081282047528536.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'THẺ TÍN DỤNG VPBank 3T',
            'pattern' => 'cards.vpbank.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6516569363824775654',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — THẺ TÍN DỤNG VPBank 3T (vpbank_3t), campaign 6516569363824775654. Pattern chỉ host con cards.vpbank.com.vn (KHÔNG match vpbank.com.vn).',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Trip.com',
            'pattern' => 'vn.trip.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6455552313033835511',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Trip.com (trip_rakuten), campaign 6455552313033835511. Host VN theo URL chiến dịch.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'VIB - THẺ TÍN DỤNG',
            'pattern' => 'vib.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6298965523515214199',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — VIB (vib_thetindung), campaign 6298965523515214199.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Vé Giá rẻ',
            'pattern' => 'vegiare.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5996042828404111774',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Vé Giá rẻ (vegiare_web), campaign 5996042828404111774.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'GỐM SỨ MINH LONG',
            'pattern' => 'minhlong.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5833135818659968932',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — GỐM SỨ MINH LONG (gomsuminhlong), campaign 5833135818659968932.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'The Coffee House',
            'pattern' => 'promothecoffeeehouse.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6699917486599106569',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — The Coffee House (thecoffeehouse_cpv), campaign 6699917486599106569. Theo URL chiến dịch (promo domain).',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'AEON ESHOP',
            'pattern' => 'aeoneshop.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6587647715581760479',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — AEON ESHOP (aeonmall_eshop), campaign 6587647715581760479. URL chiến dịch là app-link get.aeoneshop.com/a/key_live_...; pattern dùng domain đăng ký aeoneshop.com.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'Adidas Việt Nam Online (adidas.com.vn)',
            'pattern' => 'adidas.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5493145244551778830',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — Adidas Việt Nam Online (adidas_vietnam), campaign 5493145244551778830. Domain sản phẩm adidas.com.vn do người dùng cung cấp; URL chiến dịch trong API là host tracking adidasvn.pxf.io (rule cũ vẫn giữ).',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'SHOPDUNK WEB',
            'pattern' => 'shopdunk.com',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '6800497735607325103',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — SHOPDUNK WEB (shopdunk_web), campaign 6800497735607325103.',
            'source' => AffiliateDetectRule::SOURCE_DATABASE,
        ],
        [
            'name' => 'CROCS',
            'pattern' => 'crocs.com.vn',
            'match_type' => AffiliateDetectRule::MATCH_HOSTNAME,
            'platform' => 'AccessTrade',
            'strategy' => 'accesstrade_api',
            'campaign_id' => '5884407006569489397',
            'priority' => 100,
            'status' => AffiliateDetectRule::STATUS_ACTIVE,
            'description' => 'Merchant AccessTrade — CROCS (crocs_shopify), campaign 5884407006569489397.',
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
