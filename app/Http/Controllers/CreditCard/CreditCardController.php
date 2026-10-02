<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\BankService;
use App\Services\CreditCard\CategoryService;
use App\Services\CreditCard\CreditCardOverviewService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Module Thẻ tín dụng — Phase 1A.
 *
 * Phạm vi: đọc dữ liệu thật từ database `hoantien_creditcard` (connection
 * `creditcard`) và hiển thị tối thiểu. KHÔNG có CRUD, KHÔNG có ô nhập cashback,
 * KHÔNG gọi API ngân hàng, KHÔNG nâng cấp module Cashback affiliate.
 *
 * Mọi truy vấn đều scope theo `auth()->id()`. Thẻ của user khác không bao giờ
 * lọt vào response (xem `UserCardPolicy` và test `CreditCardModuleTest`).
 */
class CreditCardController extends Controller
{
    public function __construct(
        private readonly StatementPeriodService $periods,
        private readonly BankService $banks,
        private readonly CreditCardOverviewService $overview,
        private readonly CategoryService $categories,
    ) {}

    /**
     * Trang tổng quan /thetindung.
     *
     * Bốn chỉ số (tổng số thẻ, tổng hạn mức, tổng chi tiêu, cashback dự kiến) đến
     * từ `CreditCardOverviewService` — aggregate SQL, KHÔNG tính lại cashback ở
     * đây (§8/§9). Danh sách thẻ vẫn render sẵn để mở form được tức thì trên
     * mạng yếu, đúng ưu tiên mobile.
     *
     * Trang này KHÔNG có nút "+ Thêm thẻ" (chỉ nằm ở Quản lý thẻ) nhưng CÓ
     * "+ Nhập giao dịch" — nên cần danh sách thẻ dùng được + danh mục giao dịch
     * được chọn. Cả hai đã lọc ở tầng truy vấn theo `auth()->id()`.
     *
     * Trang KHÔNG hiện "Nhắc chi tiêu": ngày nhắc là metadata phụ (`spending_deadline_day`)
     * chỉ để nhở, còn kỳ sao kê do `statement_day` quyết định — hiện nó gây hiểu nhầm
     * là một mốc hạn chức năng. Ô ngày + ranh giới kỳ đã thay thế vai trò nhắc ngày.
     */
    public function index(): View
    {
        $userId = (int) auth()->id();
        $today = CarbonImmutable::now();

        $userCreditCards = UserCard::query()
            ->ownedBy($userId)
            // Phase 1B: `bank` là FK trực tiếp trên `credit_card_user_cards`.
            // Trước đây là `product.bank` (đi qua catalog sản phẩm thẻ).
            ->with(['bank', 'currentPolicy'])
            ->ordered()
            ->get();

        // Một lượt đọc cho cả 4 chỉ số lẫn số liệu từng thẻ.
        $overview = $this->overview->forPage($userId);

        return view('credit-card.index', [
            'userCreditCards' => $userCreditCards,
            'summary' => $overview['summary'],
            // Số liệu từng thẻ: chi tiêu kỳ hiện tại, tiến độ theo `desired_spend`,
            // cashback engine đã ghi và quota hoàn tiền còn lại. Khoá theo id thẻ.
            'cardMetrics' => $overview['cards'],
            // Chỉ thẻ CÒN NHẬN GIAO DỊCH mới hiện trong ô chọn thẻ của form
            // nhập giao dịch. Thẻ đã đóng vẫn hiện ở danh sách để xem lịch sử.
            'transactionCards' => $userCreditCards->filter(fn (UserCard $card): bool => $card->isUsable())->values(),
            'transactionCategories' => $this->categories->selectableFor($userId)
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    // Đánh dấu danh mục hệ thống / riêng để UI hiển thị cho rõ,
                    // không phải để quyết định quyền (quyền đã lọc ở truy vấn).
                    'scope' => $category->scope,
                ])
                ->values()
                ->all(),
            'currentPeriod' => $overview['current_periods']->first(),
            // Ranh giới kỳ hiện tại cho Ô NGÀY: chặn chọn ngoài kỳ ngay trên máy,
            // server còn chặn lại ở `StoreTransactionRequest`.
            'periodBounds' => $this->periodBounds($userCreditCards, $today),
            'today' => $today->toDateString(),
        ]);
    }

    /**
     * Ranh giới kỳ sao kê hiện tại của từng thẻ, khoá theo `user_card_id`.
     *
     * `currentBoundaries()` CHỈ TÍNH TOÁN theo `statement_day`, không truy vấn và
     * không tạo bản ghi — nên thẻ chưa có kỳ nào trong DB vẫn có ranh giới đúng,
     * và mở trang không sinh ra kỳ sao kê nào.
     *
     * @param  Collection<int, UserCard>  $cards
     * @return array<int, array{start: string, end: string}>
     */
    private function periodBounds(Collection $cards, CarbonImmutable $today): array
    {
        $bounds = [];

        foreach ($cards as $card) {
            [$start, $end] = $this->periods->currentBoundaries($card, $today);

            $bounds[(int) $card->id] = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ];
        }

        return $bounds;
    }

    /**
     * /thetindung/quan-ly-the — thêm / sửa thẻ (UX mobile-first).
     *
     * Trang render SẴN danh sách thẻ + danh sách ngân hàng + mẫu chính sách
     * (hệ thống + riêng của user). Render sẵn thay vì gọi JSON API giúp mở form
     * nhanh trên mạng yếu — đúng ưu tiên mobile — và không lặp lại quy tắc scope.
     *
     * `PolicyTemplate::scopeSelectableBy()` đã lọc ở tầng truy vấn: chỉ trả
     * template HỆ THỐNG + template RIÊNG của chính user, nên không thể lọt mẫu
     * của người khác vào danh sách chọn.
     *
     * `ruleCategories` / `ruleCombos` là danh sách target cho Policy Editor (ô
     * "Phạm vi danh mục"). Truyền kèm để editor mở được ngay, đồng thời để danh sách
     * đi qua `scopeSelectableBy()` — lọc ở tầng truy vấn, không bao giờ lộ danh mục
     * hay combo riêng của user khác. Editor này KHÔNG gọi API danh mục.
     */
    public function manage(): View
    {
        $userId = (int) auth()->id();

        return view('credit-card.manage', [
            'userCreditCards' => UserCard::query()
                ->ownedBy($userId)
                ->with(['bank', 'currentPolicy'])
                ->ordered()
                ->get(),
            'ruleCategories' => Category::query()
                ->selectableBy($userId)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])
                ->values()
                ->all(),
            'ruleCombos' => CategoryCombo::query()
                ->selectableBy($userId)
                ->with('items')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (CategoryCombo $combo): array => [
                    'id' => $combo->id,
                    'name' => $combo->name,
                    'category_count' => $combo->items->count(),
                ])
                ->values()
                ->all(),
            'banks' => $this->banks->selectable(),
            'policyTemplates' => PolicyTemplate::query()
                ->selectableBy($userId)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /** /thetindung/danh-muc — placeholder giai đoạn sau. */
    public function categories(): View
    {
        return view('credit-card.categories');
    }

    /** /thetindung/bao-cao — placeholder giai đoạn sau. */
    public function reports(): View
    {
        return view('credit-card.reports');
    }

    /** /thetindung/so-sanh — placeholder giai đoạn sau. */
    public function compare(): View
    {
        return view('credit-card.compare');
    }

    /** /thetindung/cai-dat — placeholder giai đoạn sau. */
    public function settings(): View
    {
        return view('credit-card.settings');
    }

    /**
     * /thetindung/chinh-sach — màn hình cấu hình policy (Phase 1C).
     *
     * Blade KHÔNG nhận dữ liệu policy từ server: nó gọi JSON API cùng origin để
     * tận dụng đúng một đường đọc duy nhất (API), nên không có nguy cơ hai nơi
     * hiển thị khác nhau.
     *
     * Ở đây chỉ truyền danh sách thẻ để chọn; mọi thao tác cấu hình đều qua
     * `PolicyController` / `TierController` / `CategoryRuleController`.
     */
    public function policies(): View
    {
        return view('credit-card.policies', [
            'userCreditCards' => UserCard::query()
                ->ownedBy((int) auth()->id())
                ->with('bank')
                ->ordered()
                ->get(),
        ]);
    }
}
