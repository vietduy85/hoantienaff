<?php

namespace App\Services;

use App\Models\AffiliateOrderItem;
use App\Models\User;
use App\Models\UserShopeeFashionSeenOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Thông báo "cào xu": đếm số đơn hàng Shopee thuộc danh mục thời trang của
 * chính user đang đăng nhập.
 *
 * ---------------------------------------------------------------------------
 * QUY TẮC NHẬN DIỆN
 * ---------------------------------------------------------------------------
 * Quy tắc nghiệp vụ hiện có: đơn thời trang Shopee được áp dụng tỷ lệ hoa
 * hồng Shopee 7% (khác tỷ lệ hoa hồng thường ~2,5% và KHÁC tỷ lệ cashback của
 * hệ thống). Giá trị này được lưu sẵn ở cột
 * `affiliate_order_items.shopee_commission_rate`. Vì vậy điều kiện nhận diện:
 *   platform = 'Shopee' AND shopee_commission_rate = 7.00.
 *
 * Đếm theo ĐƠN HÀNG (DISTINCT order_id) để một đơn gồm nhiều dòng sản phẩm
 * thời trang vẫn chỉ tính là 1 đơn.
 *
 * ---------------------------------------------------------------------------
 * TRƯỜNG NGÀY PHÁT SINH ĐƠN
 * ---------------------------------------------------------------------------
 * Trường ngày dùng để xác định thời điểm PHÁT SINH đơn hàng là
 * `affiliate_order_items.ordered_at` — tương ứng cột CSV "Thời Gian Đặt Hàng"
 * và `purchase_time` từ API Shopee (xem ShopeeCsvParser / ConversionsNormalizer).
 * KHÔNG dùng `created_at`/`first_imported_at` vì đó là thời điểm nhập dữ liệu
 * vào hệ thống, không phải thời điểm phát sinh đơn.
 *
 * `ordered_at` lưu giờ địa phương Việt Nam (Asia/Ho_Chi_Minh). Các mốc so sánh
 * bên dưới cũng là giờ Việt Nam.
 *
 * ---------------------------------------------------------------------------
 * GIAI ĐOẠN KHỞI TẠO THÁNG 10/2026 vs PHÁT HIỆN ĐƠN MỚI
 * ---------------------------------------------------------------------------
 * - Khởi tạo: chỉ rà soát đơn phát sinh trong tháng 10/2026
 *   [2026-10-01 00:00:00, 2026-11-01 00:00:00) — method `initialWindowOrderIds()`.
 * - Nền sàn vĩnh viễn: mọi đơn có `ordered_at >= 2026-10-01 00:00:00` đều đủ
 *   điều kiện (bao gồm cả đơn tháng 11/2026 trở đi) — method `eligibleOrderIds()`.
 *   Nền sàn này loại bỏ đơn trước tháng 10 (ví dụ tháng 9/2026) khỏi thông báo.
 * - Phát hiện đơn mới sau khởi tạo: đơn có `ordered_at >= 2026-11-01` —
 *   method `postInitializationOrderIds()`.
 *
 * Mốc ngày là HẰNG SỐ CỐ ĐỊNH, không phụ thuộc ngày chạy. Đơn cũ đã xác nhận
 * được lưu theo `order_id` trong `user_shopee_fashion_seen_orders` nên đồng bộ
 * lại (CSV/API) không làm thông báo cũ bật lại.
 */
class ShopeeFashionAlertService
{
    /** Tỷ lệ hoa hồng Shopee của danh mục thời trang (%). */
    public const FASHION_COMMISSION_RATE = 7.0;

    /** Nền sàn đủ điều kiện: đơn phát sinh từ 01/10/2026 (giờ VN), bao gồm. */
    public const WINDOW_START = '2026-10-01 00:00:00';

    /** Kết thúc cửa sổ KHỞI TẠO tháng 10/2026 (giờ VN), KHÔNG bao gồm. */
    public const INITIAL_WINDOW_END = '2026-11-01 00:00:00';

    /**
     * Truy vấn gốc: đơn Shopee thời trang (hoa hồng 7%) của chính user,
     * giới hạn trong nền sàn thời gian đủ điều kiện.
     */
    private function eligibleQuery(User $user): Builder
    {
        return AffiliateOrderItem::query()
            ->where('platform', 'Shopee')
            ->where('user_id', $user->id)
            ->where('shopee_commission_rate', self::FASHION_COMMISSION_RATE)
            ->whereNotNull('order_id')
            ->where('ordered_at', '>=', self::WINDOW_START);
    }

    /**
     * Các order_id đủ điều kiện (từ 01/10/2026 trở đi), distinct theo đơn.
     *
     * @return Collection<int, string>
     */
    public function eligibleOrderIds(User $user): Collection
    {
        return $this->eligibleQuery($user)
            ->distinct()
            ->orderBy('order_id')
            ->pluck('order_id')
            ->values();
    }

    /**
     * Các order_id thuộc cửa sổ KHỞI TẠO tháng 10/2026
     * [2026-10-01, 2026-11-01).
     *
     * @return Collection<int, string>
     */
    public function initialWindowOrderIds(User $user): Collection
    {
        return $this->eligibleQuery($user)
            ->where('ordered_at', '<', self::INITIAL_WINDOW_END)
            ->distinct()
            ->orderBy('order_id')
            ->pluck('order_id')
            ->values();
    }

    /**
     * Các order_id phát sinh SAU cửa sổ khởi tạo (>= 2026-11-01) — nhóm "đơn mới".
     *
     * @return Collection<int, string>
     */
    public function postInitializationOrderIds(User $user): Collection
    {
        return $this->eligibleQuery($user)
            ->where('ordered_at', '>=', self::INITIAL_WINDOW_END)
            ->distinct()
            ->orderBy('order_id')
            ->pluck('order_id')
            ->values();
    }

    /**
     * Các order_id user đã xác nhận "đã biết".
     *
     * @return Collection<int, string>
     */
    public function seenOrderIds(User $user): Collection
    {
        return UserShopeeFashionSeenOrder::query()
            ->where('user_id', $user->id)
            ->pluck('order_id');
    }

    /**
     * Danh sách order_id đủ điều kiện nhưng CHƯA được xác nhận.
     *
     * @return Collection<int, string>
     */
    public function unseenOrderIds(User $user): Collection
    {
        $seen = $this->seenOrderIds($user)->all();

        return $this->eligibleOrderIds($user)
            ->reject(static fn ($orderId): bool => in_array($orderId, $seen, true))
            ->values();
    }

    public function unseenCount(User $user): int
    {
        return $this->unseenOrderIds($user)->count();
    }

    public function isEnabled(User $user): bool
    {
        return ! (bool) $user->shopee_fashion_alert_disabled;
    }

    /**
     * Đánh dấu TẤT CẢ đơn đủ điều kiện hiện có của user là "đã biết".
     *
     * Idempotent: chạy lại không tạo bản ghi trùng nhờ unique (user_id, order_id)
     * + insertOrIgnore. Không ảnh hưởng tùy chọn "tắt thông báo" và không đụng
     * tới đơn của user khác. Trả về số đơn được đánh dấu.
     */
    public function acknowledge(User $user): int
    {
        $orderIds = $this->eligibleOrderIds($user);

        if ($orderIds->isEmpty()) {
            return 0;
        }

        $now = now();

        $rows = $orderIds->map(static fn ($orderId): array => [
            'user_id' => $user->id,
            'order_id' => (string) $orderId,
            'created_at' => $now,
        ])->all();

        UserShopeeFashionSeenOrder::query()->insertOrIgnore($rows);

        return $orderIds->count();
    }

    /**
     * Tắt VĨNH VIỄN thông báo "cào xu" cho user (tùy chọn theo tài khoản,
     * lưu ở cột `users.shopee_fashion_alert_disabled`).
     */
    public function disable(User $user): void
    {
        if (! $user->shopee_fashion_alert_disabled) {
            $user->forceFill(['shopee_fashion_alert_disabled' => true])->save();
        }
    }
}
