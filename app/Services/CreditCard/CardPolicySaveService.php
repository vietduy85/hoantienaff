<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Policy;
use App\Models\CreditCard\UserCard;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lưu User Card KÈM policy trong MỘT transaction.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO CẦN LỚP NÀY
 * ---------------------------------------------------------------------------
 * Nút "Lưu thẻ" trên form thêm/sửa thẻ phải ghi 3 nhóm dữ liệu: thẻ, bản policy
 * clone riêng của thẻ, và các version/tier/rule/combo snapshot đi kèm. Nếu tách
 * thành 2 request (POST thẻ, rồi POST policy) thì:
 *
 *   - POST thẻ xong, POST policy lỗi ⇒ thẻ nằm trong DB không có policy, đúng cái
 *     trạng thái mà yêu cầu cấm.
 *   - Người dùng đóng tab giữa 2 request cũng tạo ra trạng thái đó.
 *
 * Nên cả hai việc phải chung transaction. Cả `UserCardService` và
 * `PolicyService` đã tự mở transaction riêng, nhưng Laravel nest được trên cùng
 * connection (`creditcard`) bằng SAVEPOINT nên gọi lồng nhau vẫn là MỘT
 * transaction thật sự: exception ở bước policy làm rollback luôn bước thẻ.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG ĐỂ CONTROLLER TỰ GHÉP
 * ---------------------------------------------------------------------------
 * Controller chỉ nhận payload, validate, rồi gọi `create()` / `update()` ở đây.
 * Quy tắc "card có policy là một khối" là quy tắc nghiệp vụ, đặt ở controller
 * thì endpoint nào khác quên gọi là hỏng âm thầm.
 *
 * ---------------------------------------------------------------------------
 * POLICY ĐƯỢC CHỌN Ở MÀN HÌNH THÊM/SỬA THẺ
 * ---------------------------------------------------------------------------
 * `$policy['template_id']` + `$policy['tiers']` mô tả DUY NHẤT những gì user
 * đã chọn/sửa trên form. Ba nhánh:
 *
 *   - Không gửi `policy`              ⇒ giữ nguyên hiện trạng (thêm: thẻ không policy).
 *   - Có `template_id`, không tiers   ⇒ clone nguyên blueprint template.
 *   - Có `template_id` + tiers        ⇒ clone rồi ghi bằng cấu hình đã sửa, vẫn 1 version.
 *   - Không `template_id`, có tiers   ⇒ sửa policy RIÊNG của thẻ ⇒ ghi đè version đang
 *                                      chạy, KHÔNG tạo version mới.
 *
 * Nhánh cuối là nhánh của nút "Chỉnh sửa" ở màn Sửa thẻ. Nó cố tình KHÔNG theo
 * append-only của `PolicyCloneService`: bấm "Lưu thẻ" là chỉnh lại cấu hình của
 * chính mình, sinh version mới mỗi lần sẽ đầy lịch sử bản trùng nội dung. Xem
 * `PolicyService::updateCurrentVersionInPlace()` để biết chỗ nào vẫn bất biến.
 *
 * `$policy['min_total_spend']` (Vạch Min-Spend) đi theo CâNG version đó, nên:
 * thẻ luôn có policy RIÊNG (clone từ mẫu) nên chỉnh Min trên thẻ KHÔNG đụng
 * System Policy — thẻ khác chọn lại mẫu đó vẫn nhận giá trị mặc định của mẫu.
 */
class CardPolicySaveService
{
    public function __construct(
        private readonly UserCardService $cards,
        private readonly PolicyService $policies,
    ) {}

    /**
     * Thêm thẻ mới kèm policy.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $policy
     */
    public function create(int $userId, array $attributes, ?array $policy = null): UserCard
    {
        return DB::connection('creditcard')->transaction(function () use ($userId, $attributes, $policy): UserCard {
            $card = $this->cards->create($userId, $attributes);

            $this->applyPolicySelection($card, $policy, creating: true);

            return $card->refresh();
        });
    }

    /**
     * Sửa thẻ kèm policy.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $policy
     */
    public function update(int $userId, int $userCardId, array $attributes, ?array $policy = null): UserCard
    {
        return DB::connection('creditcard')->transaction(function () use ($userId, $userCardId, $attributes, $policy): UserCard {
            $card = $this->cards->update($userId, $userCardId, $attributes);

            $this->applyPolicySelection($card, $policy, creating: false);

            return $card->refresh();
        });
    }

    /**
     * Ghi lựa chọn policy của form vào thẻ vừa lưu.
     *
     * @param  array<string, mixed>|null  $policy
     */
    private function applyPolicySelection(UserCard $card, ?array $policy, bool $creating): void
    {
        if ($policy === null) {
            return;
        }

        $templateId = $policy['template_id'] ?? null;
        $tiers = $policy['tiers'] ?? null;
        $effectiveFrom = $this->effectiveFrom($policy['effective_from'] ?? null);

        // Vạch Min-Spend của version (khác `tiers[].min_total_spend` là ngưỡng bậc).
        // CHỈ đưa vào payload khi form gửi khoá: form đã có ô nhập thì gửi, còn
        // payload không có khoá thì service con rơi về giá trị mẫu — đúng hành vi cũ,
        // không bị ghi đè. Ô để trống ⇒ 0 (xoá vạch Min); cột `min_total_spend`
        // NOT NULL nên chuẩn hoá ngay ở đây thay vì đẩy `null` xuống tầng dưới.
        $minTotalSpend = array_key_exists('min_total_spend', $policy)
            ? ['min_total_spend' => $policy['min_total_spend'] ?? 0]
            : [];

        if ($templateId !== null) {
            // `cloneUserTemplate` tự phân nhánh system/own-user và từ chối
            // template của user khác — nhận luôn id client nhưng không tin nó.
            // `$tiers === null` ⇒ chỉ chọn mẫu, KHÔNG đụng blueprint; có tiers ⇒
            // clone rồi ghi đè cấu hình đã sửa, vẫn ra đúng MỘT version.
            $this->policies->cloneUserTemplate(
                $card,
                (int) $templateId,
                $effectiveFrom,
                $policy['name'] ?? null,
                $tiers === null
                    ? $minTotalSpend
                    : ['tiers' => $tiers] + $minTotalSpend,
            );

            return;
        }

        if ($tiers === null) {
            return;
        }

        if ($creating) {
            // Form thêm mà không chọn template vẫn cho sửa cấu hình tay: dựng
            // version 1 rồi áp ngay để không phát sinh version 2 rỗng.
            $this->policies->createFromScratch($card, $effectiveFrom, [
                'name' => $policy['name'] ?? 'Chính sách của tôi',
                'tiers' => $tiers,
            ] + $minTotalSpend);

            return;
        }

        if ($card->current_policy_id === null) {
            // Sửa thẻ mà thẻ chưa từng có policy (ví dụ thẻ tạo trước khi có tính
            // năng này) ⇒ đây là lần ĐẦU có cấu hình, nên tạo version 1.
            $this->policies->createFromScratch($card, $effectiveFrom, [
                'name' => $policy['name'] ?? 'Chính sách của tôi',
                'tiers' => $tiers,
            ] + $minTotalSpend);

            return;
        }

        // Sửa policy RIÊNG của thẻ ⇒ ghi đè version đang chạy, KHÔNG tạo version mới.
        // Mỗi lần bấm "Lưu thẻ" chỉ là chỉnh lại cấu hình của chính mình, nên lịch
        // sử version của thẻ không bị nhân lên các bản trùng nội dung. Bản ghi nguồn
        // (System Policy / User Policy) là dữ liệu khác nên không bị đụng, và lịch
        // sử version của System Policy vốn append-only vẫn nguyên vẹn.
        $this->policies->updateCurrentVersionInPlace($card, [
            'name' => $policy['name'] ?? null,
            'tiers' => $tiers,
        ] + $minTotalSpend);
    }

    /**
     * Ngày hiệu lực từ payload (chuỗi `Y-m-d`) thành `DateTimeInterface`.
     *
     * FormRequest đã chặn sai định dạng nên ở đây chỉ cần phòng dữ liệu rỗng và
     * trả về hôm nay.
     */
    private function effectiveFrom(mixed $value): DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: CarbonImmutable::now();
        }

        return CarbonImmutable::now();
    }

    /**
     * Policy hiện hành của thẻ sau khi lưu (dùng để trả kèm response).
     */
    public function currentPolicyOf(UserCard $card): ?Policy
    {
        return $this->policies->currentVersion($card);
    }

    /**
     * Chặn payload policy rỗng rác từ client: `tiers` phải là mảng các bậc.
     *
     * Controller gọi hàm này TRƯỚC khi mở transaction để trả 422 sớm, thay vì
     * rollback sau khi đã insert thẻ.
     *
     * @param  array<string, mixed>  $policy
     */
    public function validateSelection(array $policy): void
    {
        if (isset($policy['template_id']) && $policy['template_id'] !== null && (int) $policy['template_id'] <= 0) {
            throw new InvalidArgumentException('Chính sách đã chọn không hợp lệ.');
        }

        if (array_key_exists('tiers', $policy) && $policy['tiers'] !== null && ! is_array($policy['tiers'])) {
            throw new InvalidArgumentException('Cấu hình chính sách không hợp lệ.');
        }
    }
}
