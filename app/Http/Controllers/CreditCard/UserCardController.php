<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\StoreUserCardRequest;
use App\Http\Requests\CreditCard\UpdateUserCardRequest;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\BankService;
use App\Services\CreditCard\CategoryService;
use App\Services\CreditCard\UserCardService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API mỏng cho vòng đời thẻ tín dụng (Phase 1B).
 *
 * Controller KHÔNG chứa business logic: validate ở FormRequest, kiểm tra quyền
 * ở Policy, rồi ủy quyền cho `UserCardService`. Mọi scope đều theo
 * `auth()->id()` — không nhận `user_id` từ request.
 *
 * `App\Http\Controllers\Controller` là class rỗng nên phải `use` trait
 * `AuthorizesRequests` ở đây.
 */
class UserCardController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly UserCardService $cards,
        private readonly BankService $banks,
        private readonly CategoryService $categories,
    ) {}

    /**
     * Danh sách thẻ + dữ liệu cho form thêm/sửa.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UserCard::class);

        $userId = (int) $request->user()->id;

        return response()->json([
            'data' => $this->cards->listFor($userId)->map(fn (UserCard $card) => $this->present($card)),
            'meta' => [
                'banks' => $this->banks->selectable($request->query('q'))->map(fn ($bank) => [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'short_name' => $bank->short_name,
                    'slug' => $bank->slug,
                ]),
                'categories' => $this->categories->selectableFor($userId)
                    ->map(fn ($category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'slug' => $category->slug,
                        'scope' => $category->scope,
                    ]),
                'total_active_limit' => $this->cards->totalActiveLimit($userId),
            ],
        ]);
    }

    public function store(StoreUserCardRequest $request): JsonResponse
    {
        $this->authorize('create', UserCard::class);

        $card = $this->cards->create((int) $request->user()->id, $request->payload());

        return response()->json(['data' => $this->present($card)], 201);
    }

    public function update(UpdateUserCardRequest $request, string $userCard): JsonResponse
    {
        // Tra cứu KHÔNG lọc theo owner để Policy trả lời "403" thay vì service
        // ném "không thuộc về bạn" (thành 422). Service vẫn kiểm tra lại lần nữa
        // nên không thể bỏ qua chỉ vì controller gọi đúng.
        $card = UserCard::findOrFail((int) $userCard);

        $this->authorize('update', $card);

        $userId = (int) $request->user()->id;

        // `status` đi qua luồng deactivate/reactivate riêng để đóng/mở kỳ hợp lệ
        // (đóng ghi `closed_at`, mở lại xoá `closed_at`).
        $status = $request->requestedStatus();
        $payload = $request->payload();

        if ($status !== null) {
            unset($payload['status']);
        }

        if ($payload !== []) {
            $this->cards->update($userId, $card->id, $payload);
            $card->refresh();
        }

        if ($status === UserCard::STATUS_INACTIVE && $card->isActive()) {
            $this->cards->deactivate($userId, $card->id);
        } elseif ($status === UserCard::STATUS_ACTIVE && ! $card->isActive()) {
            $this->cards->reactivate($userId, $card->id);
        }

        return response()->json(['data' => $this->present($card->refresh())]);
    }

    /**
     * Đóng thẻ (KHÔNG xoá cứng — dữ liệu lịch sử phải còn).
     */
    public function destroy(Request $request, string $userCard): JsonResponse
    {
        $card = UserCard::findOrFail((int) $userCard);

        $this->authorize('delete', $card);

        $closed = $this->cards->deactivate((int) $request->user()->id, $card->id);

        return response()->json(['data' => $this->present($closed)]);
    }

    /**
     * Sắp xếp lại thứ tự hiển thị.
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct'],
        ]);

        $cards = $this->cards->reorder((int) $request->user()->id, $validated['order']);

        return response()->json([
            'data' => $cards->map(fn (UserCard $card) => [
                'id' => $card->id,
                'sort_order' => $card->sort_order,
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UserCard $card): array
    {
        $card->loadMissing(['bank', 'currentPolicy']);

        return [
            'id' => $card->id,
            'name' => $card->name,
            'bank' => $card->bank === null ? null : [
                'id' => $card->bank->id,
                'name' => $card->bank->name,
                'short_name' => $card->bank->short_name,
            ],
            'card_number_last4' => $card->card_number_last4,
            'credit_limit' => (float) $card->credit_limit,
            'statement_day' => $card->statement_day,
            'payment_due_day' => $card->payment_due_day,
            'spending_deadline_day' => $card->spending_deadline_day,
            'statement_date_basis' => $card->statement_date_basis,
            'opened_at' => $card->opened_at?->toDateString(),
            'closed_at' => $card->closed_at?->toDateString(),
            'status' => $card->status,
            'is_usable' => $card->isUsable(),
            'sort_order' => $card->sort_order,
            'note' => $card->note,
            'policy' => $card->currentPolicy === null ? null : [
                'id' => $card->currentPolicy->id,
                'name' => $card->currentPolicy->name,
                'version_no' => $card->currentPolicy->version_no,
            ],
        ];
    }
}
