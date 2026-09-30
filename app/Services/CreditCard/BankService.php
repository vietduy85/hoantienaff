<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Bank;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * BankService — master data ngân hàng, CHỈ ĐỌC.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG CÓ create/update/delete
 * ---------------------------------------------------------------------------
 * `credit_card_banks` là master data do hệ thống quản lý, seeded từ
 * `CreditCardSeeder`. Bank KHÔNG thuộc sở hữu user nên không có bản ghi
 * `owner_user_id` để so sánh — nếu cho user tạo/sửa, mọi thẻ đã dùng ngân hàng
 * đó sẽ đổi tên hiển thị và cashback template có thể trỏ tới bank không còn tồn
 * tại. Vì vậy service này CỐ TÌNH không có hàm ghi.
 *
 * Muốn thêm bank mới ⇒ thêm vào danh sách canonical trong seeder rồi chạy lại
 * seeder (idempotent), kèm bước kiểm tra duplicate canonical identity.
 *
 * ---------------------------------------------------------------------------
 * CANONICAL vs ALIAS
 * ---------------------------------------------------------------------------
 * Một bank = một pháp nhân. Mã cũ nằm trong cột `aliases`, KHÔNG tạo bank thứ hai.
 * Ví dụ: Sacombank có `slug = stb` và `aliases = ['scb']` ⇒ cả `stb` lẫn `scb`
 * đều tra ra cùng một bản ghi.
 */
class BankService
{
    /**
     * Danh sách bank cho dropdown "chọn ngân hàng" (Phase 1B: user chỉ chọn).
     *
     * Chỉ trả bank `is_active = true` — bank bị tắt (không còn thuộc danh sách
     * canonical) không được chọn cho thẻ MỚI, nhưng thẻ cũ vẫn đọc được vì
     * quan hệ `user_cards.bank_id` không đổi theo cờ này.
     *
     * @return Collection<int, Bank>
     */
    public function selectable(?string $keyword = null): Collection
    {
        return Bank::query()
            ->active()
            ->search($keyword)
            ->orderBy('name')
            ->get();
    }

    /**
     * Phân trang cho trang quản trị (nếu sau này cần). Vẫn chỉ đọc.
     */
    public function paginate(int $perPage = 50, ?string $keyword = null): LengthAwarePaginator
    {
        return Bank::query()
            ->search($keyword)
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Tất cả bank (kể cả đã tắt) — dùng cho màn hình đối chiếu dữ liệu.
     *
     * @return Collection<int, Bank>
     */
    public function all(): Collection
    {
        return Bank::query()->orderBy('name')->get();
    }

    public function find(int $bankId): Bank
    {
        return Bank::query()
            ->whereKey($bankId)
            ->firstOr(fn (): never => throw new \InvalidArgumentException("Ngân hàng #{$bankId} không tồn tại."));
    }

    /**
     * Bank phải tồn tại VÀ đang active để gán cho thẻ mới.
     *
     * Tách riêng khỏi `find()` vì `find()` phục vụ đọc dữ liệu thẻ cũ (bank đã tắt
     * vẫn hiển thị được), còn tạo/sửa thẻ thì không được gán bank đã tắt.
     */
    public function findActiveForAssignment(int $bankId): Bank
    {
        $bank = Bank::query()->active()->whereKey($bankId)->first();

        if ($bank === null) {
            throw new \InvalidArgumentException("Ngân hàng #{$bankId} không tồn tại hoặc không còn được phép chọn.");
        }

        return $bank;
    }

    /**
     * Tra bank theo mã người dùng gõ: canonical slug, short_name hoặc alias.
     *
     * Dùng cho import dữ liệu cũ (mã "SCB") để KHÔNG tạo bank mới. Trả null
     * nếu mã không khớp bank nào — caller tự quyết định báo lỗi hay bỏ qua.
     */
    public function resolveByCode(string $code, bool $activeOnly = true): ?Bank
    {
        $code = strtolower(trim($code));

        if ($code === '') {
            return null;
        }

        // Chỉ 43 bank canonical nên duyệt trong PHP ổn hơn nhiều so với `LIKE`
        // trên cột JSON `aliases` — và đảm bảo so khớp chính xác, tránh khớp
        // bậy cầu tiền tố (mã "s" không được ra Sacombank).
        $banks = Bank::query()
            ->when($activeOnly, fn ($query) => $query->active())
            ->get();

        return $banks->first(fn (Bank $bank): bool => $bank->matchesCode($code));
    }

    /**
     * Số canonical bank đang active — dùng cho báo cáo/kiểm tra dữ liệu.
     */
    public function countActive(): int
    {
        return Bank::query()->active()->count();
    }
}
