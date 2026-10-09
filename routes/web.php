<?php

use App\Http\Controllers\Admin\AffiliateConfigController;
use App\Http\Controllers\Admin\AffiliateShortLinkController;
use App\Http\Controllers\Admin\CreditCard\SpendQualificationAdminController;
use App\Http\Controllers\Admin\CreditCard\SpendQualificationTemplateApiController;
use App\Http\Controllers\Admin\CreditCard\SystemCategoryAdminController;
use App\Http\Controllers\Admin\CreditCard\SystemComboAdminController;
use App\Http\Controllers\Admin\CreditCard\SystemPolicyAdminController;
use App\Http\Controllers\Admin\CreditCard\SystemPolicyApiController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\OrderSyncController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WithdrawRequestController;
use App\Http\Controllers\Api\AffiliateJobController;
use App\Http\Controllers\Api\LinkRequestController;
use App\Http\Controllers\Api\PriceComparisonController;
use App\Http\Controllers\Auth\CheckUsernameController;
use App\Http\Controllers\Auth\CompleteProfileController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\CreditCard\CategoryComboController;
use App\Http\Controllers\CreditCard\CategoryController;
use App\Http\Controllers\CreditCard\CategoryRuleController;
use App\Http\Controllers\CreditCard\CreditCardController;
use App\Http\Controllers\CreditCard\CreditCardReportController;
use App\Http\Controllers\CreditCard\CreditCardSettingController;
use App\Http\Controllers\CreditCard\PolicyController;
use App\Http\Controllers\CreditCard\PolicyTemplateController;
use App\Http\Controllers\CreditCard\StatementController;
use App\Http\Controllers\CreditCard\TierController;
use App\Http\Controllers\CreditCard\TransactionController;
use App\Http\Controllers\CreditCard\TransactionHistoryController;
use App\Http\Controllers\CreditCard\UserCardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Debug\CookieDebugController;
use App\Http\Controllers\Debug\PlaywrightController;
use App\Http\Controllers\Debug\ProviderController;
use App\Http\Controllers\Debug\ShopeeLoginController;
use App\Http\Controllers\Debug\T2TestRotateSessionController;
use App\Http\Controllers\Debug\WorkerController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionNewsController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }

    return view('welcome');
});

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');

Route::get('/debug/provider', [ProviderController::class, 'index']);
Route::post('/debug/provider', [ProviderController::class, 'test']);

Route::get('/debug/worker', [WorkerController::class, 'index']);
Route::get('/debug/playwright', [PlaywrightController::class, 'index']);

Route::get('/debug/shopee-login', [ShopeeLoginController::class, 'index']);
Route::post('/debug/shopee-login/check', [ShopeeLoginController::class, 'login']);
Route::post('/debug/shopee-login/interactive', [ShopeeLoginController::class, 'loginInteractive']);
Route::post('/debug/shopee-login/session-test', [ShopeeLoginController::class, 'sessionTest']);
Route::post('/debug/shopee-login/dashboard-test', [ShopeeLoginController::class, 'dashboardTest']);
Route::post('/debug/shopee-login/profile-test', [ShopeeLoginController::class, 'profileTest']);

Route::get('/debug/cookies', [CookieDebugController::class, 'index']);
Route::get('/debug/set-cookie', [CookieDebugController::class, 'setCookie']);

Route::get('/check-username', CheckUsernameController::class);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware('forensic');
    Route::post('/link-requests', [DashboardController::class, 'store'])->name('link-requests.store')->middleware('forensic');
    Route::post('/link-requests/{linkRequest}/toggle-pin', [DashboardController::class, 'togglePin'])->name('link-requests.toggle-pin');

    // T2 v2: trả về CSRF token HIỆN TẠI của session để frontend self-recover
    // khi POST bị 419 (session/rotation tạo token mới). GET nên không bị CSRF
    // middleware chặn. Không regenerate session/token, không logout, không cache.
    Route::get('/csrf-token', function () {
        return response()->json([
            'token' => request()->session()->token(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    })->name('csrf-token');

    // TEMPORARY controlled-test tool for the T2 V2 CSRF self-recovery proof
    // (Phase 1 approved). POST-only; auth; flag-gated; user_id=5 only;
    // rotates current session id + CSRF token, returns fingerprints only.
    // Remove in Phase 8.
    Route::post('/__t2-test/rotate-session', T2TestRotateSessionController::class)
        ->name('t2-test.rotate-session');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/complete-profile', [CompleteProfileController::class, 'create'])->name('complete-profile.create');
    Route::post('/complete-profile', [CompleteProfileController::class, 'store'])->name('complete-profile.store');

    Route::get('/referrals', [ReferralController::class, 'index'])->name('referrals.index');

    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/withdraw', [WalletController::class, 'withdraw'])->name('wallet.withdraw');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{orderId}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/guide', [GuideController::class, 'index'])->name('guide.index');
    Route::get('/guide/{slug}', [GuideController::class, 'show'])->name('guide.show');

    // === Module Thẻ tín dụng (Giai đoạn 1: skeleton — routing + UI placeholder) ===
    // Tái sử dụng auth + users hiện tại. Không thay đổi cashback/affiliate.
    Route::prefix('thetindung')->name('credit-cards.')->group(function () {
        Route::get('/', [CreditCardController::class, 'index'])->name('index');
        Route::get('/quan-ly-the', [CreditCardController::class, 'manage'])->name('manage');
        Route::get('/danh-muc', [CreditCardController::class, 'categories'])->name('categories');
        // Báo cáo chi tiêu: danh sách báo cáo đã lưu + tạo/sửa/xem kết quả.
        // `{report}` ràng buộc SỐ để `/bao-cao/tao` và `/bao-cao/{id}/sua` không
        // bị nhầm thành id. Quyền kiểm qua `ReportPolicy`, không lọc ở route.
        Route::get('/bao-cao', [CreditCardReportController::class, 'index'])->name('reports');
        Route::get('/bao-cao/tao', [CreditCardReportController::class, 'create'])->name('reports.create');
        Route::post('/bao-cao', [CreditCardReportController::class, 'store'])->name('reports.store');
        Route::get('/bao-cao/{report}', [CreditCardReportController::class, 'show'])
            ->whereNumber('report')->name('reports.show');
        Route::get('/bao-cao/{report}/sua', [CreditCardReportController::class, 'edit'])
            ->whereNumber('report')->name('reports.edit');
        Route::patch('/bao-cao/{report}', [CreditCardReportController::class, 'update'])
            ->whereNumber('report')->name('reports.update');
        Route::delete('/bao-cao/{report}', [CreditCardReportController::class, 'destroy'])
            ->whereNumber('report')->name('reports.destroy');
        Route::get('/so-sanh', [CreditCardController::class, 'compare'])->name('compare');
        Route::get('/cai-dat', [CreditCardController::class, 'settings'])->name('settings');
        Route::get('/chinh-sach', [CreditCardController::class, 'policies'])->name('policies');
        Route::get('/sao-ke', [StatementController::class, 'index'])->name('statements');

        // Lịch sử giao dịch của MỘT thẻ (trang HTML, đọc + sửa nhanh).
        // Thêm giao dịch nằm ở Tổng quan để không phải rời trang tổng quan.
        Route::get('/the/{userCard}/giao-dich', [TransactionHistoryController::class, 'index'])->name('transactions');

        // === Phase 1B/1C: API domain (JSON, thin controller) ===
        // Mọi route dưới đây nằm trong middleware `auth` của group cha. Không
        // nhận `user_id` từ request — scope lấy từ `auth()->id()`.
        //
        // `throttle:credit-card-api` là limiter ĐĂNG KÝ TÊN (AppServiceProvider),
        // KHÔNG phải chuỗi số của `throttle:60,1`. Tên riêng để chắc chắn việc
        // giới hạn này không lan sang group cha — route affiliate, trang khác
        // không dùng limiter này nên không bị ảnh hưởng.
        Route::prefix('api')->name('api.')->middleware('throttle:credit-card-api')->group(function () {
            // Thẻ tín dụng.
            Route::get('/the', [UserCardController::class, 'index'])->name('cards.index');
            Route::post('/the', [UserCardController::class, 'store'])->name('cards.store');
            Route::patch('/the/thu-tu', [UserCardController::class, 'reorder'])->name('cards.reorder');
            Route::patch('/the/{userCard}', [UserCardController::class, 'update'])->name('cards.update');
            // Đóng thẻ, KHÔNG xoá cứng (dữ liệu lịch sử phải còn).
            Route::delete('/the/{userCard}', [UserCardController::class, 'destroy'])->name('cards.destroy');

            // Danh mục chi tiêu (danh mục hệ thống chỉ đọc).
            Route::get('/danh-muc', [CategoryController::class, 'index'])->name('categories.index');
            Route::post('/danh-muc', [CategoryController::class, 'store'])->name('categories.store');
            Route::patch('/danh-muc/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::delete('/danh-muc/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

            // Combo danh mục: đọc combo hệ thống (dùng chung) + CRUD combo riêng.
            // KHÔNG có route xoá — xem `CategoryComboService` (docblock "VÌ SAO KHÔNG
            // CÓ XOÁ"): combo đã được rule tham chiếu thì FK là RESTRICT.
            Route::get('/combo', [CategoryComboController::class, 'index'])->name('combos.index');
            Route::post('/combo', [CategoryComboController::class, 'store'])->name('combos.store');
            Route::patch('/combo/{combo}', [CategoryComboController::class, 'update'])->name('combos.update');

            // Giao dịch nhập tay (cashback do hệ thống tính).
            Route::get('/the/{userCard}/giao-dich', [TransactionController::class, 'index'])->name('transactions.index');
            Route::post('/giao-dich', [TransactionController::class, 'store'])->name('transactions.store');
            Route::patch('/giao-dich/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
            Route::delete('/giao-dich/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');

            // Sao kê THỰC TẾ (số đọc trên bảng kê ngân hàng) — KHÁC lịch sử
            // giao dịch ở trên. Trang HTML `/thetindung/sao-ke` và API đọc
            // dưới đây cùng gọi một hàm dựng dữ liệu nên không thể lệch nhau.
            Route::get('/sao-ke', [StatementController::class, 'apiIndex'])->name('statements.index');
            Route::post('/the/{userCard}/sao-ke', [StatementController::class, 'store'])->name('statements.store');
            Route::patch('/sao-ke/{statement}', [StatementController::class, 'update'])->name('statements.update');
// Trạng thái thanh toán: action RIÊNG, không nhét vào `statements.update`.
            // Sửa tiền chặn kỳ đã chốt còn đánh dấu "đã trả" thì không (xem
            // `CreditCardStatementPolicy::updatePayment()`) — dùng chung endpoint sẽ
            // phải nới lỏng luôn quyền sửa tiền.
            Route::patch('/sao-ke/{statement}/thanh-toan', [StatementController::class, 'updatePayment'])->name('statements.payment.update');
            // Cùng việc, nhưng theo KỲ: kỳ chưa có dòng sao kê thì chưa có `{statement}`
            // để đưa vào URL. Đây là đường để đánh dấu "đã trả" TRƯỚC khi nhập số liệu
            // — service tự tạo dòng 0/0/0. Cùng bộ quy tắc, cùng service, chỉ khác cách
            // chỉ định mục tiêu (kỳ thay vì dòng).
            Route::patch('/the/{userCard}/sao-ke/thanh-toan', [StatementController::class, 'updatePaymentForPeriod'])->name('statements.payment.update-period');
            Route::delete('/sao-ke/{statement}', [StatementController::class, 'destroy'])->name('statements.destroy');

            // Thiết lập CHUNG của user (nhắc thanh toán trước mấy ngày). Route phẳng
            // không có `{id}`: dòng thiết lập luôn thuộc `auth()->id()`, không bao
            // giờ chỉ định theo id trên URL nên không có policy và không có đường nào
            // chạm vào thiết lập của người khác.
            Route::patch('/cai-dat/nhac-thanh-toan', [CreditCardSettingController::class, 'update'])->name('settings.payment-reminder.update');
            // Đơn vị số tiền (VND | THOUSAND_VND) — cùng lập luận: route phẳng,
            // dòng thiết lập luôn thuộc `auth()->id()`, không có `{id}` để chạm
            // vào thiết lập của người khác, và không có số tiền nào trong payload
            // (đơn vị chỉ đổi cách hiển thị, DB vẫn lưu VND).
            Route::patch('/cai-dat/don-vi-tien', [CreditCardSettingController::class, 'updateMoneyUnit'])->name('settings.money-unit.update');

            // === Phase 1C: cấu hình policy cashback ===
            //
            // Route mang `userCard` dùng `Policy::forCard()` nên version của thẻ
            // khác trả 404 (không lộ ra việc id tồn tại). Bậc và rule dùng route
            // phẳng vì quyền của chúng đã resolve được qua chuỗi
            // tier → policyVersion → userCard → user_id (xem PolicyTierPolicy).
            Route::get('/the/{userCard}/chinh-sach', [PolicyController::class, 'index'])->name('policies.index');
            Route::post('/the/{userCard}/chinh-sach', [PolicyController::class, 'store'])->name('policies.store');
            Route::get('/the/{userCard}/chinh-sach/{policy}', [PolicyController::class, 'show'])->name('policies.show');
            Route::patch('/the/{userCard}/chinh-sach/{policy}', [PolicyController::class, 'update'])->name('policies.update');
            Route::post('/the/{userCard}/chinh-sach/phien-ban', [PolicyController::class, 'storeVersion'])->name('policies.versions.store');
            Route::post('/the/{userCard}/chinh-sach/mau', [PolicyController::class, 'storeTemplate'])->name('policies.templates.store');

            // Mẫu chính sách: chỉ đọc. Mẫu mới tạo bằng `policies.templates.store`.
            Route::get('/mau-chinh-sach', [PolicyTemplateController::class, 'index'])->name('templates.index');
            Route::get('/mau-chinh-sach/{template}', [PolicyTemplateController::class, 'show'])->name('templates.show');

            // Bậc chi tiêu.
            Route::get('/chinh-sach/{policy}/bac', [TierController::class, 'index'])->name('tiers.index');
            Route::post('/chinh-sach/{policy}/bac', [TierController::class, 'store'])->name('tiers.store');
            Route::patch('/bac/{tier}', [TierController::class, 'update'])->name('tiers.update');
            Route::delete('/bac/{tier}', [TierController::class, 'destroy'])->name('tiers.destroy');
            Route::post('/bac/{tier}/nhan-ban', [TierController::class, 'clone'])->name('tiers.clone');

            // Quy tắc cashback theo danh mục.
            Route::get('/bac/{tier}/quy-tac', [CategoryRuleController::class, 'index'])->name('rules.index');
            Route::post('/bac/{tier}/quy-tac', [CategoryRuleController::class, 'store'])->name('rules.store');
            Route::patch('/quy-tac/{rule}', [CategoryRuleController::class, 'update'])->name('rules.update');
            Route::delete('/quy-tac/{rule}', [CategoryRuleController::class, 'destroy'])->name('rules.destroy');
            Route::post('/quy-tac/{rule}/nhan-ban', [CategoryRuleController::class, 'clone'])->name('rules.clone');
        });
    });
});

require __DIR__.'/auth.php';

Route::prefix('api/extension')->group(function () {
    Route::get('/jobs', [AffiliateJobController::class, 'jobs']);
    Route::post('/results', [AffiliateJobController::class, 'result']);
});

Route::middleware('auth')->group(function () {
    Route::get('/api/link-request/{id}', [LinkRequestController::class, 'show']);
});

// TEST/DEVELOPMENT price comparison catalog endpoint.
// Open in local/testing so it can be checked from a browser;
// locked behind auth + Admin|Operator role in production.
Route::prefix('api/price-comparison')->group(function () {
    Route::get('/', [PriceComparisonController::class, 'searchAll'])
        ->middleware(app()->environment('production') ? ['auth', 'role:Admin|Operator'] : []);
    Route::get('/coop', [PriceComparisonController::class, 'search'])
        ->middleware(app()->environment('production') ? ['auth', 'role:Admin|Operator'] : []);
    Route::get('/bhx', [PriceComparisonController::class, 'searchBhx'])
        ->middleware(app()->environment('production') ? ['auth', 'role:Admin|Operator'] : []);
    Route::get('/kingfoodmart', [PriceComparisonController::class, 'searchKingfoodmart'])
        ->middleware(app()->environment('production') ? ['auth', 'role:Admin|Operator'] : []);
    Route::get('/winmart', [PriceComparisonController::class, 'searchWinmart'])
        ->middleware(app()->environment('production') ? ['auth', 'role:Admin|Operator'] : []);
    Route::get('/affiliate-search-links', [PriceComparisonController::class, 'affiliateSearchLinks'])
        ->middleware(app()->environment('production') ? ['auth', 'role:Admin|Operator'] : []);
});

Route::get('/so-sanh-gia', [App\Http\Controllers\PriceComparisonController::class, 'index'])->name('price-comparison.index');

Route::get('/tin-tuc-khuyen-mai', [PromotionNewsController::class, 'index'])
    ->name('promotion-news.index');
Route::get('/tin-tuc-khuyen-mai/{source}', [PromotionNewsController::class, 'source'])
    ->where('source', '[A-Za-z0-9_-]+')
    ->name('promotion-news.source');

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/withdraw-requests', [WithdrawRequestController::class, 'index'])
        ->middleware('permission:withdrawals.view')
        ->name('withdraw-requests.index');
    Route::post('/withdraw-requests/bulk-complete', [WithdrawRequestController::class, 'bulkComplete'])
        ->middleware('permission:withdrawals.manage')
        ->name('withdraw-requests.bulk-complete');
    Route::post('/withdraw-requests/{withdrawRequest}/complete', [WithdrawRequestController::class, 'complete'])
        ->middleware('permission:withdrawals.manage')
        ->name('withdraw-requests.complete');
    Route::post('/withdraw-requests/{withdrawRequest}/reject', [WithdrawRequestController::class, 'reject'])
        ->middleware('permission:withdrawals.manage')
        ->name('withdraw-requests.reject');

    Route::get('/affiliate-short-link', [AffiliateShortLinkController::class, 'index'])
        ->middleware('role:Admin|Operator')
        ->name('affiliate-short-link.index');
    Route::post('/affiliate-short-link', [AffiliateShortLinkController::class, 'store'])
        ->middleware('role:Admin|Operator')
        ->name('affiliate-short-link.store');

    Route::get('/tiktok-order-sync', [OrderSyncController::class, 'index'])
        ->middleware('role:Admin|Operator')
        ->name('tiktok-order-sync.index');
    Route::post('/tiktok-order-sync', [OrderSyncController::class, 'sync'])
        ->middleware('role:Admin|Operator')
        ->name('tiktok-order-sync.sync');

    Route::get('/affiliate-config', [AffiliateConfigController::class, 'index'])
        ->middleware('role:Admin')
        ->name('affiliate-config.index');
    Route::put('/affiliate-config', [AffiliateConfigController::class, 'update'])
        ->middleware('role:Admin')
        ->name('affiliate-config.update');

    Route::get('/finance', [FinanceController::class, 'index'])
        ->middleware('permission:withdrawals.view')
        ->name('finance.index');

    Route::get('/referrals/statistics', [ReferralController::class, 'statistics'])
        ->middleware('permission:users.view')
        ->name('referrals.statistics');

    Route::get('/users', [UserController::class, 'index'])
        ->middleware('permission:users.view')
        ->name('users.index');
    Route::get('/users/{user}', [UserController::class, 'show'])
        ->middleware('permission:users.view')
        ->name('users.show');

    Route::post('/users/{user}/wallet-adjust', [UserController::class, 'adjustWallet'])
        ->middleware('permission:users.manage')
        ->name('users.wallet-adjust');

    Route::get('/promotion-news', [App\Http\Controllers\Admin\PromotionNewsController::class, 'index'])
        ->middleware('role:Admin|Operator')
        ->name('promotion-news.index');
    Route::get('/promotion-news/create', [App\Http\Controllers\Admin\PromotionNewsController::class, 'create'])
        ->middleware('role:Admin|Operator')
        ->name('promotion-news.create');
    Route::post('/promotion-news', [App\Http\Controllers\Admin\PromotionNewsController::class, 'store'])
        ->middleware('role:Admin|Operator')
        ->name('promotion-news.store');
    Route::get('/promotion-news/{promotionNews}/edit', [App\Http\Controllers\Admin\PromotionNewsController::class, 'edit'])
        ->middleware('role:Admin|Operator')
        ->name('promotion-news.edit');
    Route::put('/promotion-news/{promotionNews}', [App\Http\Controllers\Admin\PromotionNewsController::class, 'update'])
        ->middleware('role:Admin|Operator')
        ->name('promotion-news.update');
    Route::post('/promotion-news/{promotionNews}/toggle', [App\Http\Controllers\Admin\PromotionNewsController::class, 'toggle'])
        ->middleware('role:Admin|Operator')
        ->name('promotion-news.toggle');
    Route::delete('/promotion-news/{promotionNews}', [App\Http\Controllers\Admin\PromotionNewsController::class, 'destroy'])
        ->middleware('role:Admin|Operator')
        ->name('promotion-news.destroy');

    // === Quản trị "Chính sách hoàn tiền hệ thống" (Credit Card) ===
    // Trang Blade đổ dữ liệu; mọi ghi (tạo mới / đổi version / sửa metadata) gọi
    // JSON API bên dưới qua fetch — giống kiến trúc trang + JSON API của module user.
    Route::get('/credit-card/policies', [SystemPolicyAdminController::class, 'index'])
        ->middleware('permission:credit-cards.view')
        ->name('credit-card-policies.index');
    Route::get('/credit-card/policies/create', [SystemPolicyAdminController::class, 'create'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card-policies.create');
    Route::get('/credit-card/policies/{template}', [SystemPolicyAdminController::class, 'show'])
        ->middleware('permission:credit-cards.view')
        ->name('credit-card-policies.show');
    Route::get('/credit-card/policies/{template}/edit', [SystemPolicyAdminController::class, 'edit'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card-policies.edit');
    // Editor clone (GET, KHÔNG ghi DB): mở trang "Chỉnh sửa" hydrate từ nguồn.
    Route::get('/credit-card/system-policies/{template}/clone', [SystemPolicyAdminController::class, 'cloneForm'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card.system-policies.clone');

    // JSON API cho editor admin. `throttle:credit-card-admin-api` là limiter RIÊNG
    // (AppServiceProvider), KHÔNG dùng chung bộ đếm với limiter `credit-card-api`
    // của user — admin không làm nghẽn user và ngược lại.
    Route::prefix('credit-card/api')->name('credit-card-policies.api.')
        ->middleware('throttle:credit-card-admin-api')
        ->group(function () {
            Route::get('/policies', [SystemPolicyApiController::class, 'index'])
                ->middleware('permission:credit-cards.view')
                ->name('index');
            Route::post('/policies', [SystemPolicyApiController::class, 'store'])
                ->middleware('permission:credit-cards.manage')
                ->name('store');
            Route::get('/policies/{template}', [SystemPolicyApiController::class, 'show'])
                ->middleware('permission:credit-cards.view')
                ->name('show');
            Route::get('/policies/{template}/versions', [SystemPolicyApiController::class, 'versions'])
                ->middleware('permission:credit-cards.view')
                ->name('versions');
            Route::post('/policies/{template}/versions', [SystemPolicyApiController::class, 'storeVersion'])
                ->middleware('permission:credit-cards.manage')
                ->name('versions.store');
            Route::post('/system-policies/{template}/clone', [SystemPolicyApiController::class, 'clone'])
                ->middleware('permission:credit-cards.manage')
                ->name('clone');
            Route::patch('/policies/{template}/versions/{version}', [SystemPolicyApiController::class, 'updateVersion'])
                ->middleware('permission:credit-cards.manage')
                ->name('versions.update');
            Route::delete('/policies/{template}/versions/{version}', [SystemPolicyApiController::class, 'destroyVersion'])
                ->middleware('permission:credit-cards.manage')
                ->name('versions.destroy');
            Route::post('/policies/{template}/default', [SystemPolicyApiController::class, 'setDefaultVersion'])
                ->middleware('permission:credit-cards.manage')
                ->name('default.store');
            Route::patch('/policies/{template}', [SystemPolicyApiController::class, 'update'])
                ->middleware('permission:credit-cards.manage')
                ->name('update');
        });

    // === Quản trị "Danh mục hệ thống" (Credit Card) ===
    // Chỉ 3 thao tác: thêm mới, đổi tên, sắp xếp. KHÔNG có xoá/ẩn/khôi phục.
    // Đọc dùng `credit-cards.view`, ghi dùng `credit-cards.manage` (permission có sẵn).
    Route::get('/credit-card/system-categories', [SystemCategoryAdminController::class, 'index'])
        ->middleware('permission:credit-cards.view')
        ->name('credit-card.system-categories.index');
    Route::post('/credit-card/system-categories', [SystemCategoryAdminController::class, 'store'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card.system-categories.store');
    Route::patch('/credit-card/system-categories/{category}', [SystemCategoryAdminController::class, 'update'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card.system-categories.update');
    Route::post('/credit-card/system-categories/reorder', [SystemCategoryAdminController::class, 'reorder'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card.system-categories.reorder');

    // System Combo
    Route::get('/credit-card/system-combos', [SystemComboAdminController::class, 'index'])
        ->middleware('permission:credit-cards.view')
        ->name('credit-card.system-combos.index');
    Route::post('/credit-card/system-combos', [SystemComboAdminController::class, 'store'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card.system-combos.store');
    Route::patch('/credit-card/system-combos/{combo}', [SystemComboAdminController::class, 'update'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card.system-combos.update');

    // === Quản trị "Mẫu điều kiện hoàn tiền đặc biệt" (Credit Card) ===
    // Master data: admin soạn bộ điều kiện ⇒ user chọn → hệ thống DEEP-CLONE vào
    // policy version của thẻ. Trang Blade đổ dữ liệu; ghi đi qua JSON API dưới.
    Route::get('/credit-card/spend-qualifications', [SpendQualificationAdminController::class, 'index'])
        ->middleware('permission:credit-cards.view')
        ->name('credit-card.spend-qualifications.index');
    Route::get('/credit-card/spend-qualifications/create', [SpendQualificationAdminController::class, 'create'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card.spend-qualifications.create');
    Route::get('/credit-card/spend-qualifications/{template}', [SpendQualificationAdminController::class, 'show'])
        ->middleware('permission:credit-cards.view')
        ->name('credit-card.spend-qualifications.show');
    Route::get('/credit-card/spend-qualifications/{template}/edit', [SpendQualificationAdminController::class, 'edit'])
        ->middleware('permission:credit-cards.manage')
        ->name('credit-card.spend-qualifications.edit');

    Route::prefix('credit-card/api/spend-qualifications')->name('credit-card.spend-qualifications.api.')
        ->middleware('throttle:credit-card-admin-api')
        ->group(function () {
            Route::get('/', [SpendQualificationTemplateApiController::class, 'index'])
                ->middleware('permission:credit-cards.view')
                ->name('index');
            Route::post('/', [SpendQualificationTemplateApiController::class, 'store'])
                ->middleware('permission:credit-cards.manage')
                ->name('store');
            Route::get('/{template}', [SpendQualificationTemplateApiController::class, 'show'])
                ->middleware('permission:credit-cards.view')
                ->name('show');
            Route::patch('/{template}', [SpendQualificationTemplateApiController::class, 'update'])
                ->middleware('permission:credit-cards.manage')
                ->name('update');
            Route::post('/{template}/active', [SpendQualificationTemplateApiController::class, 'setActive'])
                ->middleware('permission:credit-cards.manage')
                ->name('active');
            Route::delete('/{template}', [SpendQualificationTemplateApiController::class, 'destroy'])
                ->middleware('permission:credit-cards.manage')
                ->name('destroy');
        });
});

// Static pages - explicit routes
Route::get('/about', [StaticPageController::class, 'show'])
    ->defaults('slug', 'about')
    ->name('page.about');
Route::get('/contact', [StaticPageController::class, 'show'])
    ->defaults('slug', 'contact')
    ->name('page.contact');
Route::get('/faq', [StaticPageController::class, 'show'])
    ->defaults('slug', 'faq')
    ->name('page.faq');
Route::get('/privacy-policy', [StaticPageController::class, 'show'])
    ->defaults('slug', 'privacy-policy')
    ->name('page.privacy');
Route::get('/terms-of-service', [StaticPageController::class, 'show'])
    ->defaults('slug', 'terms-of-service')
    ->name('page.terms');
Route::get('/refund-policy', [StaticPageController::class, 'show'])
    ->defaults('slug', 'refund-policy')
    ->name('page.refund');
Route::get('/how-it-works', [StaticPageController::class, 'show'])
    ->defaults('slug', 'how-it-works')
    ->name('page.how_it_works');
Route::get('/cookie-policy', [StaticPageController::class, 'show'])
    ->defaults('slug', 'cookie-policy')
    ->name('page.cookie');

// 301 redirects from old URLs
Route::redirect('/privacy', '/privacy-policy', 301);
Route::redirect('/terms', '/terms-of-service', 301);
Route::redirect('/refund', '/refund-policy', 301);
