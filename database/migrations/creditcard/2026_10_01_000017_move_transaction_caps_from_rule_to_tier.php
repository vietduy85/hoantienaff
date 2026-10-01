<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 17/10 — chuyển "Giới hạn hoàn tiền theo giá trị giao dịch" (cap động) từ
 * RULE (category_rule_id) lên BẬC (policy_tier_id).
 *
 * Trước migration này mỗi rule mang một danh sách khoảng cap riêng. Theo nghiệp
 * vụ mới, khoảng cap là tài sản của BẬC: áp cho MỌI rule trong bậc, khớp theo
 * giá trị TỪNG giao dịch, thay thế `max_cashback_per_transaction` tĩnh của rule.
 *
 * ---------------------------------------------------------------------------
 * GIỮ NGUYÊN CÁC HÀNG CON KHI THÊM KHOA NGOẠI MỚI
 * ---------------------------------------------------------------------------
 *   1. Thêm `policy_tier_id` nullable.
 *   2. Điền `policy_tier_id` từ `credit_card_policy_tier_categories.tier_id`.
 *   3. Kiểm orphan — mọi dòng đều phải có bậc.
 *   4. GỌP/CHỐNG XUNG:
 *        - trùng (tier, min, max) cùng cap  ⇒ góp thành một dòng (giữ id nhỏ).
 *        - trùng (tier, min, max) KHÁC cap ⇒ FAIL (không đoán được cap nào đúng).
 *        - trùng (tier, min) khác max      ⇒ FAIL (mơ hồ, sẽ phá UNIQUE).
 *        - khoảng chồng lấn trong cùng bậc ⇒ FAIL (admin xử lý thủ công).
 *   5. `policy_tier_id` thành NOT NULL.
 *   6. Bỏ FK/index cũ + cột `category_rule_id`.
 *   7. Thêm FK mới (cascade theo BẬC), UNIQUE (policy_tier_id, min), index sort.
 *   8. Đánh lại `sort_order` theo (min, max) tất định cho từng bậc.
 *
 * Ở production không có dòng cap nào (cap_rows_total=0), nên các bước 4 trivially
 * pass — nhưng vẫn phải giữ: nếu có dữ liệu cấu hình mơ hồ, migration FAIL với
 * thông điệp rõ ràng thay vì âm thầm đoán.
 *
 * `down()` phục hồi: dựng lại category_rule_id, gắn cap của mỗi bậc vào rule ĐẦU
 * TIÊN của bậc đó (khôi phục không chính xác 100% — góp nhiều rule vào một bậc
 * không thể tách lại nguyên trạng).
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    private const TABLE = 'credit_card_policy_tier_category_transaction_caps';

    private const RULES_TABLE = 'credit_card_policy_tier_categories';

    private function isSqlite(): bool
    {
        return DB::connection($this->connection)->getDriverName() === 'sqlite';
    }

    public function up(): void
    {
        // 1. Cột bậc tạm thời nullable.
        Schema::connection($this->connection)->table(self::TABLE, function (Blueprint $table) {
            $table->foreignId('policy_tier_id')->nullable()->after('id');
        });

        // 2. Backfill từ rule chủ.
        $rows = DB::connection($this->connection)
            ->table(self::TABLE)
            ->join(self::RULES_TABLE, self::RULES_TABLE.'.id', '=', self::TABLE.'.category_rule_id')
            ->select([self::TABLE.'.id as cap_id', self::RULES_TABLE.'.tier_id'])
            ->get();

        foreach ($rows as $row) {
            DB::connection($this->connection)
                ->table(self::TABLE)
                ->where('id', $row->cap_id)
                ->update(['policy_tier_id' => (int) $row->tier_id]);
        }

        // 3. Không được có orphan.
        $orphans = DB::connection($this->connection)
            ->table(self::TABLE)
            ->whereNull('policy_tier_id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException("Di chuyển cap sang bậc không hoàn tất: {$orphans} dòng cap không tìm thấy bậc chủ.");
        }

        // 4. Gọp / chống xung.
        $this->mergeAndGuardConflicts();

        // 5. Bậc là bắt buộc sau khi đã nạp xong.
        Schema::connection($this->connection)->table(self::TABLE, function (Blueprint $table) {
            $table->foreignId('policy_tier_id')->nullable(false)->change();
        });

        // 6. Bỏ dây buộc cũ (SQLite theo cột, MySQL theo tên constraint).
        Schema::connection($this->connection)->table(self::TABLE, function (Blueprint $table) {
            if ($this->isSqlite()) {
                $table->dropForeign(['category_rule_id']);
            } else {
                $table->dropForeign('cc_transaction_caps_category_rule_id_foreign');
            }

            $table->dropUnique('cc_transaction_caps_rule_min_unique');
            $table->dropIndex('cc_transaction_caps_rule_sort_index');
            $table->dropColumn('category_rule_id');
        });

        // 7. Buộc lại theo BẬC (cascade khi xoá bậc) — tên constraint 64 ký tự.
        Schema::connection($this->connection)->table(self::TABLE, function (Blueprint $table) {
            $table->foreign('policy_tier_id', 'cc_transaction_caps_tier_id_foreign')
                ->references('id')->on('credit_card_policy_tiers')
                ->cascadeOnDelete();

            $table->unique(['policy_tier_id', 'min_transaction_amount'], 'cc_transaction_caps_tier_min_unique');
            $table->index(['policy_tier_id', 'sort_order'], 'cc_transaction_caps_tier_sort_index');
        });

        // 8. Đánh lại thứ tự tất định theo (min, max) trong từng bậc.
        $this->renumberSortOrder();
    }

    public function down(): void
    {
        // Tạo lại cột rule tạm thời nullable.
        Schema::connection($this->connection)->table(self::TABLE, function (Blueprint $table) {
            $table->foreignId('category_rule_id')->nullable()->after('id');
        });

        // Gắn cap của mỗi bậc vào rule ĐẦU TIÊN của bậc (khôi phục best-effort).
        $capsByTier = DB::connection($this->connection)
            ->table(self::TABLE)
            ->whereNotNull('policy_tier_id')
            ->orderBy('id')
            ->get()
            ->groupBy('policy_tier_id');

        foreach ($capsByTier as $tierId => $tierCaps) {
            $firstRuleId = DB::connection($this->connection)
                ->table(self::RULES_TABLE)
                ->where('tier_id', (int) $tierId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');

            if ($firstRuleId === null) {
                throw new RuntimeException("Không thể phục hồi cap của bậc #{$tierId}: bậc không còn rule nào để gắn.");
            }

            foreach ($tierCaps as $capRow) {
                DB::connection($this->connection)
                    ->table(self::TABLE)
                    ->where('id', $capRow->id)
                    ->update(['category_rule_id' => (int) $firstRuleId]);
            }
        }

        // Bỏ dây buộc theo bậc (SQLite theo cột, MySQL theo tên constraint), xoá cột
        // bậc, dựng lại dây buộc cũ theo rule.
        Schema::connection($this->connection)->table(self::TABLE, function (Blueprint $table) {
            if ($this->isSqlite()) {
                $table->dropForeign(['policy_tier_id']);
            } else {
                $table->dropForeign('cc_transaction_caps_tier_id_foreign');
            }

            $table->dropUnique('cc_transaction_caps_tier_min_unique');
            $table->dropIndex('cc_transaction_caps_tier_sort_index');
            $table->dropColumn('policy_tier_id');
        });

        Schema::connection($this->connection)->table(self::TABLE, function (Blueprint $table) {
            $table->foreign('category_rule_id', 'cc_transaction_caps_category_rule_id_foreign')
                ->references('id')->on('credit_card_policy_tier_categories')
                ->cascadeOnDelete();

            $table->unique(['category_rule_id', 'min_transaction_amount'], 'cc_transaction_caps_rule_min_unique');
            $table->index(['category_rule_id', 'sort_order'], 'cc_transaction_caps_rule_sort_index');
        });
    }

    /**
     * Gọp trùng lặp tuyệt đối + chống xung khi GÓP NHIỀU RULE vào một bậc.
     *
     * Mỗi rule cũ đã tự không chồng lấn nội bộ, nhưng hai rule KHÁC files có thể
     * khai các khoảng trùng / chồng nhau. Ở cấp bậc mới các khoảng này là MỘT
     * danh sách ⇒ nào mơ hồ (chênh cap, qua mốc "Từ", chồng lấn) là FAIL, không
     * đoán. Trùng tuyệt đối (tier, min, max, cap giống hệt) thì gọi là một.
     */
    private function mergeAndGuardConflicts(): void
    {
        $rows = DB::connection($this->connection)
            ->table(self::TABLE)
            ->select(['id', 'policy_tier_id', 'min_transaction_amount', 'max_transaction_amount', 'max_cashback_per_transaction'])
            ->orderBy('id')
            ->get();

        $tiers = $rows->groupBy('policy_tier_id');

        foreach ($tiers as $tierId => $tierRows) {
            // (a) Cùng khoảng [min, max] mà cap KHÁC nhau ⇒ không góp được.
            $byRange = $tierRows->groupBy(fn (object $row): string => (string) $row->min_transaction_amount.'|'.(string) $row->max_transaction_amount);

            foreach ($byRange as $rangeKey => $sameRange) {
                $distinctCaps = $sameRange->pluck('max_cashback_per_transaction')->unique()->values();

                if ($distinctCaps->count() > 1) {
                    throw new RuntimeException(sprintf(
                        'Không thể nâng cap động lên bậc #%s: khoảng "%s" có nhiều cap hoàn khác nhau (%s). Hãy rà soát cấu hình thủ công.',
                        $tierId,
                        $rangeKey,
                        $distinctCaps->join(', '),
                    ));
                }
            }

            // (b) TRÙNG "Từ" mà khác "Đến" ⇒ mơ hồ + sẽ phá UNIQUE (tier, min).
            $byMin = $tierRows->groupBy('min_transaction_amount');

            foreach ($byMin as $min => $sameMin) {
                if ($sameMin->pluck('max_transaction_amount')->unique()->count() > 1) {
                    throw new RuntimeException(sprintf(
                        'Không thể nâng cap động lên bậc #%s: mốc "Từ" %s xuất hiện nhiều hơn một khoảng khác "Đến". Hãy rà soát cấu hình thủ công.',
                        $tierId,
                        $min,
                    ));
                }
            }

            // (c) Gọp trùng tuyệt đối: giữ id nhỏ nhất, xoá phần thừa.
            foreach ($byRange as $sameRange) {
                $keepId = $sameRange->min('id');

                foreach ($sameRange->where('id', '!=', $keepId) as $duplicate) {
                    DB::connection($this->connection)
                        ->table(self::TABLE)
                        ->where('id', $duplicate->id)
                        ->delete();
                }
            }
        }

        // (d) Sau khi gọp, các khoảng CÒN LẠI trong cùng bậc không được chồng lấn.
        $remaining = DB::connection($this->connection)
            ->table(self::TABLE)
            ->select(['policy_tier_id', 'min_transaction_amount', 'max_transaction_amount'])
            ->orderBy('policy_tier_id')
            ->orderBy('id')
            ->get()
            ->groupBy('policy_tier_id');

        foreach ($remaining as $tierId => $tierRows) {
            $sorted = $tierRows
                ->sortBy([
                    fn (object $row): float => (float) $row->min_transaction_amount,
                    fn (object $row): float => $row->max_transaction_amount === null ? PHP_FLOAT_MAX : (float) $row->max_transaction_amount,
                ])
                ->values();

            $previousMax = null;

            foreach ($sorted as $row) {
                $min = (float) $row->min_transaction_amount;

                if ($previousMax !== null && $min <= $previousMax) {
                    throw new RuntimeException(sprintf(
                        'Không thể nâng cap động lên bậc #%s: các khoảng chồng lấn hoặc trùng nhau sau khi gộp. Hãy rà soát cấu hình thủ công.',
                        $tierId,
                    ));
                }

                $previousMax = $row->max_transaction_amount === null ? PHP_FLOAT_MAX : (float) $row->max_transaction_amount;
            }
        }
    }

    private function renumberSortOrder(): void
    {
        $tierIds = DB::connection($this->connection)
            ->table(self::TABLE)
            ->select('policy_tier_id')
            ->distinct()
            ->orderBy('policy_tier_id')
            ->pluck('policy_tier_id');

        foreach ($tierIds as $tierId) {
            $capIds = DB::connection($this->connection)
                ->table(self::TABLE)
                ->where('policy_tier_id', $tierId)
                ->orderBy('min_transaction_amount')
                ->orderBy('id')
                ->pluck('id');

            $order = 1;

            foreach ($capIds as $capId) {
                DB::connection($this->connection)
                    ->table(self::TABLE)
                    ->where('id', $capId)
                    ->update(['sort_order' => $order++]);
            }
        }
    }
};
