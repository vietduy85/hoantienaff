<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Models\CreditCard\Category;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\Transaction;
use App\Services\CreditCard\CategoryService;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\UserCardService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Trang lịch sử giao dịch của MỘT thẻ — /thetindung/the/{userCard}/giao-dich.
 *
 * Trang này chỉ ĐỌC và SỬA. Việc thêm giao dịch nằm ở Tổng quan (form nhập tay
 * ngay trên trang) nên người dùng không phải rời trang tổng quan để ghi một khoản
 * chi — đó là luồng dùng nhiều nhất trên điện thoại.
 *
 * Phạm vi dữ liệu: mọi truy vấn đều khoá theo `user_card_id` đã resolve từ
 * `auth()->id()`; không nhận `user_id` từ request. Thẻ của user khác trả 404
 * (`findOwned()`), không lộ ra việc id tồn tại.
 *
 * Sửa giao dịch dùng lại API `credit-cards.api.transactions.update` — tức là
 * `UpdateTransactionRequest` + `CreditCardTransactionService::update()`: chuyển
 * kỳ sao kê nếu ngày đổi kỳ, tính lại cashback và recalculate cả kỳ cũ lẫn kỳ
 * mới trong một transaction. Trang KHÔNG tự tính lại cashback (§8/§9).
 */
class TransactionHistoryController extends Controller
{
    use AuthorizesRequests;

    /** Số giao dịch mỗi trang. */
    private const PER_PAGE = 25;

    public function __construct(
        private readonly UserCardService $cards,
        private readonly CreditCardTransactionService $transactions,
        private readonly CategoryService $categories,
    ) {}

    public function index(Request $request, string $userCard): View
    {
        $userId = (int) $request->user()->id;

        // `findOwned()` ném `InvalidArgumentException` để chặn ở tầng service. Ở
        // đây — một TRANG HTML — biến nó thành 404: không lộ ra việc id tồn tại và
        // không trả trang lỗi 500 cho người dùng gõ nhầm đường dẫn.
        try {
            $card = $this->cards->findOwned((int) $userCard, $userId);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        $this->authorize('view', $card);

        // Kỳ và danh mục dùng cho bộ lọc + form sửa. Nạp trước để validate bằng
        // `Rule::in()` CHÍNH THẺ NÀY.
        //
        // Cố tình KHÔNG dùng `Rule::exists('credit_card_statement_periods')`:
        // module nằm trên connection `creditcard`, còn rule của Laravel chạy trên
        // connection mặc định — sẽ tra nhầm bảng (hoặc không có bảng) và mọi
        // filter hợp lệ sẽ bị từ chối.
        $periods = StatementPeriod::query()
            ->where('user_card_id', $card->id)
            ->orderByDesc('period_start')
            ->get();

        $categories = $this->categories->selectableFor($userId)
            ->map(fn (Category $category): array => [
                'id' => (int) $category->id,
                'name' => $category->name,
                'scope' => $category->scope,
            ])
            ->values()
            ->all();

        // Validate ngay tại cửa sổ: mọi giá trị lọc đi thẳng vào truy vấn. `keyword`
        // bind an toàn nên chỉ cần giới hạn độ dài.
        $validated = $request->validate([
            'period_id' => ['nullable', 'integer', Rule::in($periods->pluck('id')->all())],
            'category_id' => ['nullable', Rule::in(array_column($categories, 'id'))],
            'keyword' => ['nullable', 'string', 'max:100'],
        ]);

        // `validate()` trả về cả khoá rỗng; bỏ đi để `listFor()` không áp bộ
        // lọc rỗng (`isset()` trên `null` vẫn là true).
        $filters = array_filter($validated, fn ($value): bool => $value !== null && $value !== '');

        $all = $this->transactions->listFor($card, $filters);

        // `listFor()` trả Collection (dùng chung với API JSON nên không đổi kiểu
        // trả về), nên phân trang bằng `forPage()` thay vì `paginate()`. Phạm vi
        // chỉ một thẻ nên kích thước bộ nhớ ở đây chấp nhận được.
        $page = max(1, $request->integer('page', 1));
        $total = $all->count();
        $pageTransactions = $all->forPage($page, self::PER_PAGE)->values();

        return view('credit-card.transactions', [
            'card' => $card,
            // Dữ liệu đã present sẵn cho Alpine: form sửa mở tức thì không cần
            // gọi mạng (ưu tiên mạng yếu) — vẫn render server-side bên dưới.
            'rows' => $pageTransactions->map(fn (Transaction $t): array => $this->present($t))->values()->all(),
            'total' => $total,
            'page' => $page,
            'lastPage' => max(1, (int) ceil($total / self::PER_PAGE)),
            'periods' => $periods,
            'categories' => $categories,
            'filters' => $filters,
        ]);
    }

    /**
     * Một dòng lịch sử cho Alpine.
     *
     * Tiền gửi dạng CHUỖI (không ép float) để không mất chính xác khi đi qua JSON;
     * client chỉ định dạng, KHÔNG cộng trừ gì.
     *
     * `editable` chỉ để hiện lý do trên UI — quyền thật do `TransactionPolicy`
     * chặn lại ở server (kỳ đã chốt thì sửa cũng không đi qua được).
     *
     * @return array<string, mixed>
     */
    private function present(Transaction $transaction): array
    {
        $period = $transaction->statementPeriod;

        return [
            'id' => (int) $transaction->id,
            'transaction_date' => $transaction->transaction_date?->toDateString(),
            'amount' => (string) $transaction->amount,
            'category_id' => $transaction->category_id === null ? null : (int) $transaction->category_id,
            'note' => $transaction->note,
            'cashback_amount' => $transaction->cashback_amount_snapshot === null
                ? null
                : (string) $transaction->cashback_amount_snapshot,
            // Kỳ đã chốt ⇒ đã vào bảng kê đã trả, để nguyên.
            'editable' => ! ($period !== null && $period->isFinalized()),
        ];
    }
}
