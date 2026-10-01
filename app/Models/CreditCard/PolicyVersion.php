<?php

namespace App\Models\CreditCard;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * PolicyVersion — MỘT version cụ thể của Card Policy.
 *
 * Cố ý dùng CHUNG bảng `credit_card_policies` với `Policy` (spec Phase 1A chốt
 * đúng 10 bảng, không có bảng `credit_card_policy_versions`).
 *
 * Version là khái niệm NỘI BỘ: user nhìn thấy "MB JCB Ultimate Cashback", không
 * thấy "Version 1 / 2 / 3". Hệ thống tự resolve version theo kỳ sao kê.
 *
 * Business rules của một version là BẤT BIẾN. Khi policy đổi, `PolicyCloneService`
 * tạo version mới; version cũ chỉ bị đóng `effective_to` + chuyển `superseded`
 * (metadata vòng đời, không phải business rule).
 *
 * @property int $id
 * @property int|null $user_card_id
 * @property int $root_policy_id
 * @property int $version_no
 * @property string $status
 * @property string $name
 */
class PolicyVersion extends Policy
{
    /**
     * Bản ghi root (version_no = 1, root_policy_id NULL lúc tạo).
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class, 'root_policy_id');
    }

    public function isFirstVersion(): bool
    {
        return (int) $this->version_no === 1;
    }

    /**
     * Đếm các tham chiếu khiến version này KHÔNG được xóa.
     *
     * Thẻ/transaction được deep clone nên thường trỏ vào bản ghi riêng của thẻ;
     * nhưng nếu dữ liệu lịch sử (kỳ finalize, test...) trỏ thẳng vào blueprint,
     * vẫn phải chặn xóa để không làm vỡ snapshot.
     *
     * @return array{transactions: int, periods: int, cards: int}
     */
    public function usageCounts(): array
    {
        return self::usageCountsForPolicy((int) $this->id);
    }

    /**
     * @return array{transactions: int, periods: int, cards: int}
     */
    public static function usageCountsForPolicy(int $policyId): array
    {
        $tierIds = PolicyTier::query()->where('policy_id', $policyId)->pluck('id');
        $ruleIds = PolicyTierCategory::query()->whereIn('tier_id', $tierIds)->pluck('id');

        $transactions = DB::connection('creditcard')->table('credit_card_transactions')
            ->where(function ($query) use ($policyId, $tierIds, $ruleIds): void {
                $query->where('policy_version_id', $policyId)
                    ->orWhereIn('policy_tier_id', $tierIds)
                    ->orWhereIn('policy_tier_category_id', $ruleIds);
            })
            ->count();

        $periods = DB::connection('creditcard')->table('credit_card_statement_periods')
            ->where('policy_id', $policyId)
            ->count();

        $cards = DB::connection('creditcard')->table('credit_card_user_cards')
            ->where('current_policy_id', $policyId)
            ->count();

        return [
            'transactions' => $transactions,
            'periods' => $periods,
            'cards' => $cards,
        ];
    }

    public function isReferenced(): bool
    {
        return array_sum($this->usageCounts()) > 0;
    }
}
