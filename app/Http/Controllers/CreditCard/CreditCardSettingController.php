<?php

namespace App\Http\Controllers\CreditCard;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditCard\UpdateCreditCardMoneyUnitRequest;
use App\Http\Requests\CreditCard\UpdateCreditCardSettingRequest;
use App\Services\CreditCard\CreditCardUserSettingService;
use Illuminate\Http\JsonResponse;

/**
 * Thiết lập CHUNG của người dùng cho module Thẻ tín dụng.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG CẦN POLICY
 * ---------------------------------------------------------------------------
 * Mọi thao tác ở đây ghi vào dòng thiết lập CỦA CHÍNH người đang đăng nhập, lấy
 * `user_id` từ `auth()->id()` chứ không bao giờ từ request. Không có đường nào để
 * chỉ định `user_id` khác, nên không có dữ liệu của người khác để phải bảo vệ —
 * cùng lập luận với các route dùng chung (`BankController`, `CategoryController`).
 *
 * Ngược lại, đổi trạng thái thanh toán của một DÒNG SAO KÊ thì nhận id từ URL nên
 * vẫn phải qua `CreditCardStatementPolicy` — xem `StatementController::updatePayment()`.
 */
class CreditCardSettingController extends Controller
{
    public function __construct(private readonly CreditCardUserSettingService $settings) {}

    /**
     * Lưu số ngày nhắc thanh toán trước.
     *
     * Không có nút "Lưu": đổi số ⇒ lưu ngay. Trả về số ngày server đang giữ (đã
     * chuẩn hoá) để giao diện kéo về đúng thứ thực sự được lưu, thay vì giữ nguyên
     * giá trị người vừa gõ — cùng nguyên tắc với `applyPayment()` ở màn Sao kê.
     */
    public function update(UpdateCreditCardSettingRequest $request): JsonResponse
    {
        [$setting, $days] = $this->settings->updateReminderDays(
            (int) $request->user()->id,
            $request->reminderDays(),
        );

        return response()->json([
            'data' => [
                'user_id' => (int) $setting->user_id,
                'payment_reminder_days' => $days,
            ],
        ]);
    }

    /**
     * Lưu đơn vị số tiền (VND | THOUSAND_VND).
     *
     * Tương tự `update()`: chọn là lưu ngay, không có nút "Lưu". Trả về đơn vị
     * server THỰC SỰ giữ (đã chuẩn hoá) để giao diện đồng bộ về đúng giá trị
     * trong DB thay vì giữ nguyên lựa chọn vừa bấm.
     *
     * Đổi đơn vị KHÔNG đổi một đồng nào trong dữ liệu: DB vẫn lưu VND, chỉ cách
     * đọc số trên màn hình đổi — nên không có payload tiền nào đi qua endpoint này.
     */
    public function updateMoneyUnit(UpdateCreditCardMoneyUnitRequest $request): JsonResponse
    {
        $setting = $this->settings->updateMoneyUnit(
            (int) $request->user()->id,
            $request->moneyUnit(),
        );

        return response()->json([
            'data' => [
                'user_id' => (int) $setting->user_id,
                'money_unit' => $setting->money_unit,
            ],
        ]);
    }
}