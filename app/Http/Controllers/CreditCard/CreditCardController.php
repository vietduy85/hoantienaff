<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
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
    ) {}

    /**
     * Trang tổng quan /thetindung.
     *
     * Số liệu lấy thật từ DB (mặc định 0 / "Chưa có dữ liệu"), không hard-code.
     */
    public function index(): View
    {
        $userId = (int) auth()->id();

        $userCreditCards = UserCard::query()
            ->ownedBy($userId)
            // Phase 1B: `bank` là FK trực tiếp trên `credit_card_user_cards`.
            // Trước đây là `product.bank` (đi qua catalog sản phẩm thẻ).
            ->with(['bank', 'currentPolicy'])
            ->ordered()
            ->get();

        $totalLimit = (float) $userCreditCards->sum('credit_limit');

        return view('credit-card.index', [
            'userCreditCards' => $userCreditCards,
            'totalCards' => $userCreditCards->count(),
            'totalLimit' => $totalLimit,
            'currentPeriod' => $this->currentPeriodFor($userId),
            'deadlineWarnings' => $this->deadlineWarnings($userCreditCards),
        ]);
    }

    /**
     * Kỳ sao kê hiện tại của thẻ đầu tiên của user.
     *
     * Trang tổng quan là GET nên chỉ ĐỌC: dùng `findForDate()` để không sinh bản
     * ghi mới khi user chỉ mở trang. Muốn tạo kỳ thì gọi
     * `resolvePeriodForDate()` ở luồng ghi (import giao dịch).
     */
    private function currentPeriodFor(int $userId): ?StatementPeriod
    {
        $card = UserCard::query()->ownedBy($userId)->orderBy('id')->first();

        if ($card === null) {
            return null;
        }

        return $this->periods->findForDate($card, CarbonImmutable::now());
    }

    /**
     * Cảnh báo "nên chi tiêu trước ngày X" — CHỈ NHẮC NHỞ, không ảnh hưởng kỳ.
     *
     * `spendingDeadlineWarning()` cần ngày chốt kỳ, lấy từ
     * `boundariesForDate()` (tính thuần theo `statement_day`) nên cảnh báo vẫn
     * đúng kể cả khi kỳ chưa được tạo trong DB.
     *
     * @param  Collection<int, UserCard>  $cards
     * @return array<int, array{name: string, message: string}>
     */
    private function deadlineWarnings($cards): array
    {
        $today = CarbonImmutable::now();
        $warnings = [];

        foreach ($cards as $card) {
            [, $periodEnd] = $this->periods->boundariesForDate($card, $today);

            $message = $card->spendingDeadlineWarning($today, $periodEnd);

            if ($message !== null) {
                $warnings[] = ['name' => $card->name, 'message' => $message];
            }
        }

        return $warnings;
    }

    /** /thetindung/quan-ly-the — placeholder giai đoạn sau. */
    public function manage(): View
    {
        return view('credit-card.manage');
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
