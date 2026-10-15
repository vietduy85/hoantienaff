<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\UpdateCumulativeSpendRequest;
use App\Models\CreditCard\UserCard;
use App\Services\CreditCard\CreditCardCumulativeSpendService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * API mỏng cho MỐC CHI TIÊU LŨY KẾ / phí thường niên của một thẻ.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO ĐỨNG RIÊNG
 * ---------------------------------------------------------------------------
 * Tab "Phí thường niên và chi tiêu tích lũy" ở trang Sao kê chỉ có MỘT hành động
 * ghi: cập nhật mốc lũy kế kỳ trước. Đây không phải nghiệp vụ sao kê (không đụng
 * `credit_card_statements`, không chạy engine cashback), nên không nhét vào
 * `StatementController`. Controller KHÔNG chứa công thức: validate ở FormRequest,
 * quyền ở `UserCardPolicy::update`, tính toán + nhật ký ở
 * `CreditCardCumulativeSpendService`.
 *
 * ---------------------------------------------------------------------------
 * TRA CỨU KHÔNG LỌC OWNER, ĐỂ POLICY TRẢ 403
 * ---------------------------------------------------------------------------
 * Cùng cách `UserCardController::update`: thẻ của người khác trả 403 ("không phải
 * của bạn") thay vì 404/422 mơ hồ. Service vẫn kiểm tra lại lần nữa.
 */
class AnnualFeeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CreditCardCumulativeSpendService $cumulative,
    ) {
    }

    /**
     * PATCH /thetindung/api/the/{userCard}/chi-tieu-luy-ke
     */
    public function update(UpdateCumulativeSpendRequest $request, string $userCard): JsonResponse
    {
        $card = UserCard::findOrFail((int) $userCard);

        $this->authorize('update', $card);

        try {
            $updated = $this->cumulative->update(
                $card,
                (int) $request->user()->id,
                $request->payload(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Trả nguyên hàng đã tính lại từ server (tiến độ mới) để client vẽ lại
        // đúng số máy chủ đang giữ, KHÔNG tự cộng ở trình duyệt.
        return response()->json([
            'data' => $this->cumulative->row($updated, CarbonImmutable::now()),
        ]);
    }
}
