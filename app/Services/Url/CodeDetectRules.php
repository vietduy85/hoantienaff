<?php

namespace App\Services\Url;

/**
 * READ-ONLY registry of the URL detection rules that are hard-coded in the
 * application (Shopee / TikTok / Lazada / ShopeeFood / AccessTrade placeholder).
 *
 * This is descriptive metadata only — it does NOT drive detection. It is the
 * single source for the "Chuỗi nhận diện URL" panel and for conflict/duplicate
 * checks when an admin adds a rule. Extracted verbatim from the former
 * AffiliateConfigController::detectRules() so the displayed values are
 * unchanged.
 */
class CodeDetectRules
{
    /**
     * @return array<string, array{rules: array<int, array<string, string>>, note: ?string}>
     */
    public function all(): array
    {
        return [
            'shopee' => [
                'rules' => [
                    [
                        'pattern' => 'shopee',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct/Extension + Admin — detectPlatform()',
                        'source' => 'code',
                        'method' => 'Direct Link / Extension Worker',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shp.ee',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct/Extension + Admin — detectPlatform()',
                        'source' => 'code',
                        'method' => 'Direct Link / Extension Worker',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 's.shopee.vn, vn.shp.ee, s.shp.ee, shope.ee',
                        'match' => 'hostname',
                        'scope' => 'UrlResolverService::isShortLink() — resolve link rút gọn',
                        'source' => 'code',
                        'method' => 'Direct Link (→ s.shopee.vn/an_redir)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shopee.vn',
                        'match' => 'hostname',
                        'scope' => 'UrlResolverService::isShopeeLanding() — xác thực landing',
                        'source' => 'code',
                        'method' => 'Direct Link',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shopee',
                        'match' => 'substring',
                        'scope' => 'ProviderFactory::detectPlatform() — /debug/provider + BenchmarkAffiliate',
                        'source' => 'code',
                        'method' => 'Providers/AffiliateService (không dùng ở luồng thật)',
                        'status' => 'diagnostic',
                    ],
                ],
                'note' => '4 nguồn thuộc luồng tạo link thật (3 bản detectPlatform trùng nhau + resolver). Chuỗi con khớp cả shopee.vn.example.com.',
            ],
            'tiktok' => [
                'rules' => [
                    [
                        'pattern' => 'tiktok',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct Link — detectPlatform() (đặt nhãn platform)',
                        'source' => 'code',
                        'method' => '— (chỉ gán nhãn)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'tiktok',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct Link — nhánh non-Shopee: str_contains(original_url, "tiktok")',
                        'source' => 'code',
                        'method' => 'Direct Link (RioHub API)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'tiktok',
                        'match' => 'substring',
                        'scope' => 'ProviderFactory::detectPlatform() — /debug/provider + BenchmarkAffiliate',
                        'source' => 'code',
                        'method' => 'TikTokProvider (không dùng ở luồng thật)',
                        'status' => 'diagnostic',
                    ],
                ],
                'note' => 'Detect 2 lần trong cùng request (nhãn + dispatch); đều là substring trên toàn URL nên evil.com/tiktok cũng khớp.',
            ],
            'lazada' => [
                'rules' => [
                    [
                        'pattern' => 'lazada',
                        'match' => 'substring',
                        'scope' => 'Dashboard Direct Link — detectPlatform() + nhánh non-Shopee',
                        'source' => 'code',
                        'method' => 'Direct Link (Lazada API)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'lazada.vn, lazada.sg, lazada.com.my, lazada.co.th, lazada.com.ph, lazada.co.id, lazada.shop',
                        'match' => 'hostname',
                        'scope' => 'LazadaUrlParser::DOMAINS / isLazadaHost() — validate trong LazadaLinkEstimateService',
                        'source' => 'code',
                        'method' => 'Direct Link (Lazada API)',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'lazada',
                        'match' => 'substring',
                        'scope' => 'ProviderFactory::detectPlatform() — /debug/provider + BenchmarkAffiliate',
                        'source' => 'code',
                        'method' => 'LazadaProvider (không dùng ở luồng thật)',
                        'status' => 'diagnostic',
                    ],
                ],
                'note' => '2 nguồn trong luồng thật: substring (dispatch) + hostname (validate). Substring khớp cả lazada.evil.com.',
            ],
            'shopeefood' => [
                'rules' => [
                    [
                        'pattern' => 'shopeefood.vn (+ subdomain)',
                        'match' => 'hostname',
                        'scope' => 'ShopeeFoodUrlParser::isShopeeFoodHost() → DashboardController::isShopeeFoodUrl() (chạy TRƯỚC khi chọn strategy)',
                        'source' => 'code',
                        'method' => 'ShopeeFood Deep Link',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'spf.shopee.vn',
                        'match' => 'hostname',
                        'scope' => 'ShopeeFoodUrlParser::SPF_HOST + ShopeeFoodAffiliateResolver — resolve short link',
                        'source' => 'code',
                        'method' => 'ShopeeFood Deep Link',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shopeefood.shopee.vn',
                        'match' => 'hostname',
                        'scope' => 'ShopeeFoodUrlParser::AFFILIATE_HOST + ShopeeFoodOpenGraphService',
                        'source' => 'code',
                        'method' => 'ShopeeFood Deep Link',
                        'status' => 'active',
                    ],
                    [
                        'pattern' => 'shopeefood.shopee.vnnow-food',
                        'match' => 'hostname',
                        'scope' => 'ShopeeFoodUrlParser::VNNOW_HOST — normalize về host chuẩn',
                        'source' => 'code',
                        'method' => 'ShopeeFood Deep Link',
                        'status' => 'active',
                    ],
                ],
                'note' => 'BẮT BUỘC kiểm tra ShopeeFood TRƯỚC Shopee: chuỗi con "shopee" khớp cả "shopeefood"; đảo thứ tự sẽ nhận nhầm ShopeeFood thành Shopee.',
            ],
            'accesstrade' => [
                'rules' => [],
                'note' => 'Chưa cấu hình domain merchant/campaign. Không có rule detect nào trong code.',
            ],
        ];
    }

    /**
     * Flattened list of code rules for conflict detection: pattern + match type.
     *
     * @return array<int, array{pattern: string, match: string}>
     */
    public function flat(): array
    {
        $flat = [];

        foreach ($this->all() as $group) {
            foreach ($group['rules'] as $rule) {
                // Comma-separated host lists are stored as one display row; split
                // them so each domain can be compared individually.
                foreach (preg_split('/\s*,\s*/', $rule['pattern']) ?: [] as $token) {
                    $token = trim(preg_replace('/\s*\(.*\)$/', '', $token) ?? $token);
                    if ($token !== '') {
                        $flat[] = ['pattern' => $token, 'match' => $rule['match']];
                    }
                }
            }
        }

        return $flat;
    }
}
