<?php

use App\Http\Controllers\Auth\CheckUsernameController;
use App\Http\Controllers\Auth\CompleteProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route('dashboard');
    }
    return view('welcome');
});

Route::get('/auth/google', [App\Http\Controllers\Auth\GoogleController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [App\Http\Controllers\Auth\GoogleController::class, 'callback'])->name('google.callback');

Route::get('/debug/provider', [App\Http\Controllers\Debug\ProviderController::class, 'index']);
Route::post('/debug/provider', [App\Http\Controllers\Debug\ProviderController::class, 'test']);

Route::get('/debug/worker', [App\Http\Controllers\Debug\WorkerController::class, 'index']);
Route::get('/debug/playwright', [App\Http\Controllers\Debug\PlaywrightController::class, 'index']);

Route::get('/debug/shopee-login', [App\Http\Controllers\Debug\ShopeeLoginController::class, 'index']);
Route::post('/debug/shopee-login/check', [App\Http\Controllers\Debug\ShopeeLoginController::class, 'login']);
Route::post('/debug/shopee-login/interactive', [App\Http\Controllers\Debug\ShopeeLoginController::class, 'loginInteractive']);
Route::post('/debug/shopee-login/session-test', [App\Http\Controllers\Debug\ShopeeLoginController::class, 'sessionTest']);
Route::post('/debug/shopee-login/dashboard-test', [App\Http\Controllers\Debug\ShopeeLoginController::class, 'dashboardTest']);
Route::post('/debug/shopee-login/profile-test', [App\Http\Controllers\Debug\ShopeeLoginController::class, 'profileTest']);

Route::get('/debug/cookies', [App\Http\Controllers\Debug\CookieDebugController::class, 'index']);
Route::get('/debug/set-cookie', [App\Http\Controllers\Debug\CookieDebugController::class, 'setCookie']);

Route::get('/check-username', CheckUsernameController::class);

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/link-requests', [DashboardController::class, 'store'])->name('link-requests.store');
    Route::post('/link-requests/{linkRequest}/toggle-pin', [DashboardController::class, 'togglePin'])->name('link-requests.toggle-pin');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/complete-profile', [CompleteProfileController::class, 'create'])->name('complete-profile.create');
    Route::post('/complete-profile', [CompleteProfileController::class, 'store'])->name('complete-profile.store');

    Route::get('/wallet', [App\Http\Controllers\WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/withdraw', [App\Http\Controllers\WalletController::class, 'withdraw'])->name('wallet.withdraw');
    Route::get('/orders', [App\Http\Controllers\OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{orderId}', [App\Http\Controllers\OrderController::class, 'show'])->name('orders.show');
    Route::get('/guide', [GuideController::class, 'index'])->name('guide.index');
    Route::get('/guide/{slug}', [GuideController::class, 'show'])->name('guide.show');
});

require __DIR__.'/auth.php';

Route::prefix('api/extension')->group(function () {
    Route::get('/jobs', [App\Http\Controllers\Api\AffiliateJobController::class, 'jobs']);
    Route::post('/results', [App\Http\Controllers\Api\AffiliateJobController::class, 'result']);
});

Route::middleware('auth')->group(function () {
    Route::get('/api/link-request/{id}', [App\Http\Controllers\Api\LinkRequestController::class, 'show']);
});

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/withdraw-requests', [App\Http\Controllers\Admin\WithdrawRequestController::class, 'index'])
        ->middleware('permission:withdrawals.view')
        ->name('withdraw-requests.index');
    Route::post('/withdraw-requests/bulk-complete', [App\Http\Controllers\Admin\WithdrawRequestController::class, 'bulkComplete'])
        ->middleware('permission:withdrawals.manage')
        ->name('withdraw-requests.bulk-complete');
    Route::post('/withdraw-requests/{withdrawRequest}/complete', [App\Http\Controllers\Admin\WithdrawRequestController::class, 'complete'])
        ->middleware('permission:withdrawals.manage')
        ->name('withdraw-requests.complete');
    Route::post('/withdraw-requests/{withdrawRequest}/reject', [App\Http\Controllers\Admin\WithdrawRequestController::class, 'reject'])
        ->middleware('permission:withdrawals.manage')
        ->name('withdraw-requests.reject');

    Route::get('/affiliate-short-link', [App\Http\Controllers\Admin\AffiliateShortLinkController::class, 'index'])
        ->middleware('role:Admin|Operator')
        ->name('affiliate-short-link.index');
    Route::post('/affiliate-short-link', [App\Http\Controllers\Admin\AffiliateShortLinkController::class, 'store'])
        ->middleware('role:Admin|Operator')
        ->name('affiliate-short-link.store');

    Route::get('/tiktok-order-sync', [App\Http\Controllers\Admin\OrderSyncController::class, 'index'])
        ->middleware('role:Admin|Operator')
        ->name('tiktok-order-sync.index');
    Route::post('/tiktok-order-sync', [App\Http\Controllers\Admin\OrderSyncController::class, 'sync'])
        ->middleware('role:Admin|Operator')
        ->name('tiktok-order-sync.sync');

    Route::get('/affiliate-config', [App\Http\Controllers\Admin\AffiliateConfigController::class, 'index'])
        ->middleware('role:Admin')
        ->name('affiliate-config.index');
    Route::put('/affiliate-config', [App\Http\Controllers\Admin\AffiliateConfigController::class, 'update'])
        ->middleware('role:Admin')
        ->name('affiliate-config.update');

    Route::get('/finance', [App\Http\Controllers\Admin\FinanceController::class, 'index'])
        ->middleware('permission:withdrawals.view')
        ->name('finance.index');

    Route::get('/users', [App\Http\Controllers\Admin\UserController::class, 'index'])
        ->middleware('permission:users.view')
        ->name('users.index');
    Route::get('/users/{user}', [App\Http\Controllers\Admin\UserController::class, 'show'])
        ->middleware('permission:users.view')
        ->name('users.show');

    Route::post('/users/{user}/wallet-adjust', [App\Http\Controllers\Admin\UserController::class, 'adjustWallet'])
        ->middleware('permission:users.manage')
        ->name('users.wallet-adjust');
});

// Static pages - explicit routes
Route::get('/about', [App\Http\Controllers\StaticPageController::class, 'show'])
    ->defaults('slug', 'about')
    ->name('page.about');
Route::get('/contact', [App\Http\Controllers\StaticPageController::class, 'show'])
    ->defaults('slug', 'contact')
    ->name('page.contact');
Route::get('/faq', [App\Http\Controllers\StaticPageController::class, 'show'])
    ->defaults('slug', 'faq')
    ->name('page.faq');
Route::get('/privacy-policy', [App\Http\Controllers\StaticPageController::class, 'show'])
    ->defaults('slug', 'privacy-policy')
    ->name('page.privacy');
Route::get('/terms-of-service', [App\Http\Controllers\StaticPageController::class, 'show'])
    ->defaults('slug', 'terms-of-service')
    ->name('page.terms');
Route::get('/refund-policy', [App\Http\Controllers\StaticPageController::class, 'show'])
    ->defaults('slug', 'refund-policy')
    ->name('page.refund');
Route::get('/how-it-works', [App\Http\Controllers\StaticPageController::class, 'show'])
    ->defaults('slug', 'how-it-works')
    ->name('page.how_it_works');
Route::get('/cookie-policy', [App\Http\Controllers\StaticPageController::class, 'show'])
    ->defaults('slug', 'cookie-policy')
    ->name('page.cookie');

// 301 redirects from old URLs
Route::redirect('/privacy', '/privacy-policy', 301);
Route::redirect('/terms', '/terms-of-service', 301);
Route::redirect('/refund', '/refund-policy', 301);
