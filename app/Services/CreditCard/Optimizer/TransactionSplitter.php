<?php

namespace App\Services\CreditCard\Optimizer;

/**
 * TransactionSplitter — tìm cách CHIA một khoản chi của một danh mục trên một thẻ
 * thành nhiều giao dịch nhỏ, khi policy có trần hoàn tiền THEO TỪNG GIAO DỊCH.
 *
 * Động cơ: trần giao dịch (cố định ở rule hoặc động theo khoảng giá trị ở bậc)
 * làm giảm hoàn tiền khi dồn cả khoản chi vào một dòng. Cùng tổng chi nhưng chia
 * thành nhiều giao dịch có thể chạm trần nhiều lần ⇒ hoàn nhiều hơn. Đây là mô
 * phỏng thuần; engine (`CashbackCalculator`) vẫn là nơi quyết định số cuối cùng.
 *
 * KHÔNG hardcode tỷ lệ/trần sản phẩm: mọi mốc chia suy TỪ policy thật của thẻ
 * (cap giao dịch theo bậc + cap giao dịch ở rule + min_transaction_amount) cộng
 * một mốc tham chiếu 250.000đ.
 */
final class TransactionSplitter
{
    /** Mốc tham chiếu chung khi policy không cho mốc rõ ràng nào khác. */
    public const LANDMARK_AMOUNT = 250000;

    /** Số mốc chia tối đa xét cho một danh mục (chặn chi phí đánh giá). */
    public const MAX_CANDIDATES = 6;

    /** Chặn số giao dịch sinh ra cho một danh mục (chặn nổ số dòng). */
    public const MAX_TRANSACTIONS = 100;

    /**
     * Thẻ có bất kỳ trần giao dịch nào (rule cố định HOẶC khoảng giá trị ở bậc)
     * không — tức việc chia giao dịch có thể thay đổi kết quả.
     */
    public function hasPotential(CardScaffold $scaffold): bool
    {
        foreach ($scaffold->allEnabledRules as $rule) {
            $cap = $rule['max_cashback_per_transaction'] ?? null;

            if ($cap !== null && (float) $cap > 0.0) {
                return true;
            }
        }

        foreach ($scaffold->capsByTier as $caps) {
            if ($caps !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Danh mục này có rule/trần giao dịch đáng để thử chia không.
     */
    public function supportsCategory(CardScaffold $scaffold, int $categoryId): bool
    {
        foreach ($scaffold->allEnabledRules as $rule) {
            if (! $this->ruleMatchesCategory($rule, $categoryId)) {
                continue;
            }

            $cap = $rule['max_cashback_per_transaction'] ?? null;

            if ($cap !== null && (float) $cap > 0.0) {
                return true;
            }

            $min = $rule['min_transaction_amount'] ?? null;

            if ($min !== null && (float) $min > 0.0) {
                return true;
            }
        }

        foreach ($scaffold->capsByTier as $caps) {
            foreach ($caps as $cap) {
                if ((float) $cap['max_cashback_per_transaction'] > 0.0
                    || (float) $cap['min_transaction_amount'] > 0.0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Kích thước giao dịch ứng viên (VND) cho một danh mục. Luôn bao gồm chính
     * `$amount` (phương án KHÔNG chia) ở cuối; các mốc khác là điểm chạm trần/đạt
     * sàn suy từ policy. Danh sách TĂNG DẦN, đã lọc trùng và chặn số lượng.
     *
     * @return list<int>
     */
    public function candidateChunkSizes(CardScaffold $scaffold, int $categoryId, int $amount): array
    {
        if ($amount <= 0) {
            return [];
        }

        $rate = $this->maxRatePercent($scaffold, $categoryId);
        $sizes = [];

        foreach ($scaffold->allEnabledRules as $rule) {
            if (! $this->ruleMatchesCategory($rule, $categoryId)) {
                continue;
            }

            $percent = (float) $rule['cashback_percent'];
            $capTx = $rule['max_cashback_per_transaction'] ?? null;

            if ($percent > 0.0 && $capTx !== null && (float) $capTx > 0.0) {
                $sizes[] = (int) round((float) $capTx / ($percent / 100.0));
            }

            $minTx = $rule['min_transaction_amount'] ?? null;

            if ($minTx !== null && (float) $minTx > 0.0) {
                $sizes[] = (int) round((float) $minTx);
            }
        }

        foreach ($scaffold->capsByTier as $caps) {
            foreach ($caps as $cap) {
                $min = (float) $cap['min_transaction_amount'];
                $max = $cap['max_transaction_amount'];
                $capCashback = (float) $cap['max_cashback_per_transaction'];

                if ($rate > 0.0 && $capCashback > 0.0) {
                    $reach = (int) round($capCashback / ($rate / 100.0));

                    if ($min > 0.0 && $reach < $min) {
                        $reach = (int) round($min);
                    }

                    if ($max !== null && $reach > (float) $max) {
                        $reach = (int) round((float) $max);
                    }

                    $sizes[] = $reach;
                }

                if ($min > 0.0) {
                    $sizes[] = (int) round($min);
                }
            }
        }

        $sizes[] = self::LANDMARK_AMOUNT;

        $clean = [];

        foreach ($sizes as $size) {
            if ($size > 0 && $size < $amount) {
                $clean[$size] = true;
            }
        }

        $list = array_keys($clean);
        sort($list);

        if (count($list) > self::MAX_CANDIDATES - 1) {
            $list = array_slice($list, 0, self::MAX_CANDIDATES - 1);
        }

        $list[] = $amount;
        $list = array_values(array_unique($list));
        sort($list);

        return $list;
    }

    /**
     * Chia `$amount` thành các giao dịch có kích thước `$chunkSize` (dòng cuối là
     * phần dư). Tổng luôn BẢO TOÀN đúng `$amount`.
     *
     * @return list<int>
     */
    public function splitInto(int $amount, int $chunkSize): array
    {
        if ($amount <= 0 || $chunkSize <= 0 || $chunkSize >= $amount) {
            return [$amount];
        }

        $chunks = [];
        $remaining = $amount;
        $guard = 0;

        while ($remaining > 0 && $guard++ < self::MAX_TRANSACTIONS) {
            $chunk = $remaining >= $chunkSize ? $chunkSize : $remaining;
            $chunks[] = $chunk;
            $remaining -= $chunk;
        }

        if ($remaining > 0) {
            $chunks[count($chunks) - 1] += $remaining;
        }

        return $chunks;
    }

    private function maxRatePercent(CardScaffold $scaffold, int $categoryId): float
    {
        $max = 0.0;

        foreach ($scaffold->allEnabledRules as $rule) {
            if (! $this->ruleMatchesCategory($rule, $categoryId)) {
                continue;
            }

            $max = max($max, (float) $rule['cashback_percent']);
        }

        return $max;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function ruleMatchesCategory(array $rule, int $categoryId): bool
    {
        if (($rule['scope_type'] ?? 'category') === 'other') {
            return true;
        }

        $members = $rule['combo_category_ids'] ?? [];

        if ($members !== []) {
            return in_array($categoryId, array_map('intval', $members), true);
        }

        return (int) ($rule['category_id'] ?? 0) === $categoryId;
    }
}
