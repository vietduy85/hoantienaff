<?php

namespace App\Providers;

use App\Services\AffiliateSearchLinks\AffiliateSearchLinkManager;
use App\Services\AffiliateSearchLinks\Providers\LazadaAffiliateSearchLinkProvider;
use App\Services\AffiliateSearchLinks\Providers\ShopeeAffiliateSearchLinkProvider;
use App\Services\AffiliateSearchLinks\Providers\TikTokAffiliateSearchLinkProvider;
use App\Services\PriceComparison\PriceComparisonManager;
use App\Services\PriceComparison\Providers\BachHoaXanhProvider;
use App\Services\PriceComparison\Providers\CoopOnlineProvider;
use App\Services\PriceComparison\Providers\KingfoodmartProvider;
use App\Services\PriceComparison\Providers\WinMartProvider;
use App\Services\PromotionNews\PromotionNewsManager;
use App\Services\PromotionNews\Providers\BhxPromotionNewsProvider;
use App\Services\PromotionNews\Providers\CoopPromotionNewsProvider;
use App\Services\PromotionNews\Providers\KingfoodmartPromotionNewsProvider;
use App\Services\PromotionNews\Providers\WinMartPromotionNewsProvider;
use App\Services\ProviderFactory;
use App\Services\Providers\AgodaProvider;
use App\Services\Providers\BookingProvider;
use App\Services\Providers\LazadaProvider;
use App\Services\Providers\LongChauProvider;
use App\Services\Providers\PharmacityProvider;
use App\Services\Providers\ShopeeProvider;
use App\Services\Providers\TikTokProvider;
use App\Services\Providers\TravelokaProvider;
use App\View\Composers\PendingWithdrawBadgeComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            ShopeeProvider::class,
            LazadaProvider::class,
            TikTokProvider::class,
            LongChauProvider::class,
            PharmacityProvider::class,
            TravelokaProvider::class,
            AgodaProvider::class,
            BookingProvider::class,
        ], 'affiliate-providers');

        $this->app->when(ProviderFactory::class)
            ->needs('$providers')
            ->giveTagged('affiliate-providers');

        $this->app->tag([
            CoopOnlineProvider::class,
            BachHoaXanhProvider::class,
            KingfoodmartProvider::class,
            WinMartProvider::class,
        ], 'catalog-providers');

        $this->app->when(PriceComparisonManager::class)
            ->needs('$providers')
            ->giveTagged('catalog-providers');

        $this->app->tag([
            ShopeeAffiliateSearchLinkProvider::class,
            LazadaAffiliateSearchLinkProvider::class,
            TikTokAffiliateSearchLinkProvider::class,
        ], 'affiliate-search-link-providers');

        $this->app->when(AffiliateSearchLinkManager::class)
            ->needs('$providers')
            ->giveTagged('affiliate-search-link-providers');

        $this->app->tag([
            CoopPromotionNewsProvider::class,
            BhxPromotionNewsProvider::class,
            WinMartPromotionNewsProvider::class,
            KingfoodmartPromotionNewsProvider::class,
        ], 'promotion-news-providers');

        $this->app->when(PromotionNewsManager::class)
            ->needs('$providers')
            ->giveTagged('promotion-news-providers');
    }

    public function boot(): void
    {
        if (! $this->app->isLocal()) {
            URL::forceScheme('https');
        }

        $this->registerCreditCardApiRateLimiter();
        $this->registerCreditCardAdminApiRateLimiter();

        // Badge số yêu cầu rút tiền đang chờ, hiển thị cạnh "Trang chủ" trong
        // navigation dùng chung. Composer tự lọc theo quyền `withdrawals.view`.
        View::composer('layouts.navigation', PendingWithdrawBadgeComposer::class);
    }

    /**
     * Giới hạn tần suất CHỈ cho API Credit Card.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO KHÔNG DÙNG `RateLimiter::for('api')`
     * ---------------------------------------------------------------------------
     * Tên `api` là quy ước của Laravel và rất dễ bị gắn nhầm: nếu ai đó thêm
     * `throttle:api` vào group cha thì toàn bộ route khác của ứng dụng (affiliate,
     * tài khoản…) cũng bị giới hạn theo cùng một bộ đếm. Đặt tên riêng
     * `credit-card-api` khiến việc gắn throttle CHỈ có tác dụng khi cố ý gắn vào
     * group `credit-cards.api.*` — không thể vô tình kéo theo route khác.
     *
     * ---------------------------------------------------------------------------
     * VÌ SAO GIỚI HẠN THEO USER, KHÔNG THEO IP
     * ---------------------------------------------------------------------------
     * Group cha đã bọc `auth`, nên `Limit::perUser()` dùng `request()->user()->id`:
     * nhiều người sau cùng một NAT (văn phòng, điện thoại) không đụng nhau, và
     * một tài khoản bị lạm dụng không khóa được người dùng chung IP.
     */
    private function registerCreditCardApiRateLimiter(): void
    {
        RateLimiter::for('credit-card-api', function (Request $request) {
            return Limit::perMinute(self::creditCardApiRateLimit())
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Bạn gửi yêu cầu quá nhiều. Vui lòng thử lại sau.',
                    ], 429, $headers);
                });
        });
    }

    private static function creditCardApiRateLimit(): int
    {
        $configured = env('CREDIT_CARD_API_RATE_LIMIT');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 60;
    }

    /**
     * Limiter RIÊNG cho API quản trị chính sách hệ thống (`/admin/credit-card/api`).
     *
     * Tách khỏi `credit-card-api` (limiter của user) vì:
     *   1. Hai đối tượng khác nhau, hai mức độ tin cậy khác nhau — gộp chung một bộ
     *      đếm sẽ cho phép admin làm nghẽn lượt gọi của user và ngược lại.
     *   2. Admin thao tác nặng hơn (tạo/đổi version kèm toàn bộ tier/rule), nên mức
     *      giới hạn đặt cao hơn, không "quá thấp".
     * Giới hạn theo USER như limiter user, không theo IP.
     */
    private function registerCreditCardAdminApiRateLimiter(): void
    {
        RateLimiter::for('credit-card-admin-api', function (Request $request) {
            return Limit::perMinute(self::creditCardAdminApiRateLimit())
                ->by($request->user()?->getAuthIdentifier() ?? $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Bạn gửi yêu cầu quá nhiều. Vui lòng thử lại sau.',
                    ], 429, $headers);
                });
        });
    }

    private static function creditCardAdminApiRateLimit(): int
    {
        $configured = env('CREDIT_CARD_ADMIN_API_RATE_LIMIT');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 120;
    }
}
