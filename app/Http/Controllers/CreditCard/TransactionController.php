<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreTransactionRequest;
use App\Http\Requests\CreditCard\UpdateTransactionRequest;
use App\Models\CreditCard\Transaction;
use App\Services\CreditCard\CreditCardTransactionService;
use App\Services\CreditCard\UserCardService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API mỏng cho giao dịch nhập tay.
 *
 * Controller không nhận `cashback_amount`/`cashback_percent` — cashback luôn do
 * `CashbackRecordService` tính qua pipeline. Response trả kèm snapshot để UI
 * hiển thị, nhưng client KHÔNG gửi lên được.
 */
class TransactionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UserCardService $cards,
        private readonly CreditCardTransactionService $transactions,
    ) {}

    public function index(Request $request, string $userCard): JsonResponse
    {
        $card = $this->cards->findOwned((int) $userCard, (int) $request->user()->id);

        $this->authorize('view', $card);

        $filters = $request->only(['period_id', 'category_id', 'from', 'to', 'keyword']);

        return response()->json([
            'data' => $this->transactions->listFor($card, $filters)
                ->map(fn (Transaction $t) => $this->present($t)),
        ]);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $card = $this->cards->findOwned((int) $request->input('user_card_id'), $userId);

        $this->authorize('create', Transaction::class);

        $transaction = $this->transactions->create($card, $request->payload());

        return response()->json(['data' => $this->present($transaction)], 201);
    }

    public function update(UpdateTransactionRequest $request, string $transaction): JsonResponse
    {
        // Tra cứu không lọc owner ⇒ Policy trả 403 cho giao dịch của user khác.
        // `TransactionPolicy` cũng chặn giao dịch trong kỳ đã finalize.
        $model = Transaction::findOrFail((int) $transaction);

        $this->authorize('update', $model);

        $userId = (int) $request->user()->id;

        $updated = $this->transactions->update($userId, $model->id, $request->payload());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function destroy(Request $request, string $transaction): JsonResponse
    {
        $model = Transaction::findOrFail((int) $transaction);

        $this->authorize('delete', $model);

        $this->transactions->delete((int) $request->user()->id, $model->id);

        return response()->json(['deleted' => true, 'id' => $model->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Transaction $t): array
    {
        $t->loadMissing(['category', 'statementPeriod']);

        return [
            'id' => $t->id,
            'user_card_id' => $t->user_card_id,
            'transaction_date' => $t->transaction_date?->toDateString(),
            'posted_date' => $t->posted_date?->toDateString(),
            'amount' => (float) $t->amount,
            'merchant' => $t->merchant,
            'note' => $t->note,
            'source' => $t->source,
            'category' => $t->category === null ? null : [
                'id' => $t->category->id,
                'name' => $t->category->name,
            ],
            // Ba khoá này để client gộp ngược vào dòng lịch sử sau khi lưu
            // (trang Lịch sử dùng `category_id` mở form, `category_name` in ra
            // dòng, `editable` quyết định nút Sửa) — trước đây payload API thiếu
            // nên sau một lần lưu, row mất `editable` và không mở lại được form.
            'category_id' => $t->category_id === null ? null : (int) $t->category_id,
            'category_name' => $t->category?->name,
            'editable' => ! ($t->statementPeriod !== null && $t->statementPeriod->isFinalized()),
            'period' => $t->statementPeriod === null ? null : [
                'id' => $t->statementPeriod->id,
                'start' => $t->statementPeriod->period_start?->toDateString(),
                'end' => $t->statementPeriod->period_end?->toDateString(),
                'status' => $t->statementPeriod->status,
            ],
            // Chỉ đọc — hệ thống tính, user không nhập tay.
            'cashback_amount' => $t->cashback_amount_snapshot === null ? null : (float) $t->cashback_amount_snapshot,
            'cashback_percent' => $t->cashback_percent_snapshot === null ? null : (float) $t->cashback_percent_snapshot,
            'is_eligible' => $t->is_eligible,
            'ineligible_reason' => $t->ineligible_reason,
        ];
    }
}
