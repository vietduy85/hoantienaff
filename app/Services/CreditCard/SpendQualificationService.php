<?php

namespace App\Services\CreditCard;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\SpendQualification;
use App\Models\CreditCard\SpendQualificationCondition;
use App\Models\CreditCard\SpendQualificationConditionExcludedCategory;
use App\Models\CreditCard\SpendQualificationTemplate;
use App\Models\CreditCard\SpendQualificationTemplateCondition;
use App\Models\CreditCard\SpendQualificationTemplateConditionExcludedCategory;
use App\Models\CreditCard\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * SpendQualificationService — "Điều kiện hoàn tiền đặc biệt".
 *
 * ---------------------------------------------------------------------------
 * TRÁCH NHIỆM
 * ---------------------------------------------------------------------------
 *   1. Ghi / đọc payload điều kiện cho MẪU (template) và cho POLICY VERSION.
 *   2. Deep-clone điều kiện giữa các phiên bản (append-only / chọn template).
 *   3. ĐÁNH GIÁ điều kiện khi tính cashback (gate của kỳ sao kê).
 *
 * ---------------------------------------------------------------------------
 * BẮT BUỘC THEO SPEC (đã chốt với user — KHÔNG tự ý đổi business rule)
 * ---------------------------------------------------------------------------
 *   - Điều kiện đo trên CHI TIÊU THỰC TẾ của giao dịch trong kỳ sao kê,
 *     KHÔNG phải eligible spend, KHÔNG phải cashback, KHÔNG theo tier rate.
 *   - `category`: SUM(amount thực tế của đúng category đó) >= min_spend.
 *   - `other` ("Lĩnh vực khác"): tổng amount thực tế CẢ kỳ − SUM(amount thực tế
 *     của các danh mục bị LOẠI TRỪ) >= min_spend. Excluded rỗng = cả kỳ.
 *   - Mọi điều kiện gộp bằng AND. 0 điều kiện hoặc qualification bị tắt = no-op
 *     (cashback tính y hệt khi chưa có tính năng này).
 *   - Mỗi qualification tối đa MỘT `other`, và `other` LUÔN đứng cuối.
 *   - `source_template_id` CHỈ là dấu vết nguồn, không bao giờ được dùng để
 *     tham chiếu runtime khi tính cashback.
 */
class SpendQualificationService
{
    /**
     * Payload điều kiện của MỘT template (null = template không có điều kiện).
     *
     * @return array{
     *   enabled: bool, name: string|null, note: string|null,
     *   conditions: array<int, array<string, mixed>>
     * }|null
     */
    public function payloadForTemplate(int $templateId): ?array
    {
        $template = SpendQualificationTemplate::query()->find($templateId);

        if ($template === null) {
            return null;
        }

        return $this->serializeConditions(
            $template->conditions()->with('excludedCategories')->get()
        );
    }

    /**
     * Payload điều kiện của MỘT policy version (null = version không có điều kiện).
     *
     * @return array{
     *   enabled: bool, name: string|null, note: string|null,
     *   conditions: array<int, array<string, mixed>>
     * }|null
     */
    public function payloadForPolicyVersion(int $policyVersionId): ?array
    {
        $qualification = SpendQualification::query()
            ->where('policy_version_id', $policyVersionId)
            ->first();

        if ($qualification === null) {
            return null;
        }

        return [
            'enabled' => (bool) $qualification->enabled,
            'name' => $qualification->name,
            'note' => $qualification->note,
            'conditions' => $this->serializeConditions(
                $qualification->conditions()->with('excludedCategories')->get()
            )['conditions'],
            'source_template_id' => $qualification->source_template_id,
        ];
    }

    /**
     * Ghi đè toàn bộ điều kiện của MỘT template (quản trị, blade/API admin).
     *
     * `$payload === null` ⇒ xoá bộ điều kiện của template (không bao giờ xoá
     * template — xoá template do luồng delete riêng của CRUD quyết định).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function persistTemplate(int $templateId, ?array $payload): void
    {
        // Template là master data hệ thống ⇒ chỉ tham chiếu danh mục hệ thống
        // đang hoạt động (userId = null).
        $normalized = $payload === null ? null : $this->normalizeConditions($payload['conditions'] ?? [], null);

        DB::connection('creditcard')->transaction(function () use ($templateId, $payload, $normalized): void {
            $this->replaceTemplateConditions($templateId, $normalized);

            if (is_array($payload)) {
                SpendQualificationTemplate::query()->whereKey($templateId)->update([
                    'description' => $this->nullableText($payload['description'] ?? null),
                    'note' => $this->nullableText($payload['note'] ?? null),
                ]);
            }
        });
    }

    /**
     * Xoá hẳn một template "Điều kiện hoàn tiền đặc biệt" (quản trị).
     *
     * Template đang được policy version nào đó clone (dấu vết `source_template_id`)
     * KHÔNG được xoá — chỉ có thể vô hiệu hoá (`is_active = false`). Việc vô hiệu
     * hoá đặt ở controller/api (ghi thẳng cột `is_active` của template).
     *
     * @throws InvalidArgumentException nếu template đang được dùng.
     */
    public function deleteTemplate(int $templateId): void
    {
        $template = SpendQualificationTemplate::query()->findOrFail($templateId);

        if ($template->isUsedByAnyQualification()) {
            throw new InvalidArgumentException('Mẫu đang được các thẻ sử dụng, không thể xoá. Bạn có thể tắt (vô hiệu hoá) mẫu thay vì xoá.');
        }

        DB::connection('creditcard')->transaction(function () use ($templateId): void {
            SpendQualificationTemplateConditionExcludedCategory::query()
                ->whereIn('condition_id', SpendQualificationTemplateCondition::query()->where('template_id', $templateId)->select('id'))
                ->delete();

            SpendQualificationTemplateCondition::query()->where('template_id', $templateId)->delete();

            SpendQualificationTemplate::query()->whereKey($templateId)->delete();
        });
    }

    /**
     * Ghi đè bộ điều kiện của MỘT policy version.
     *
     * - `$payload === null` ⇒ XOÁ bộ điều kiện của version (báo hiệu rõ "không
     *   dùng điều kiện"), KHÁC với việc KHÔNG gửi khoá ở payload cha (giữ nguyên).
     * - `$userId === null` ⇒ bản ghi thuộc system (blueprint admin): danh mục
     *   phải là hệ thống đang hoạt động.
     * - `$userId !== null` ⇒ bản ghi thuộc policy của thẻ: danh mục phải là hệ
     *   thống đang hoạt động hoặc của chính user (đang hoạt động).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function persistForPolicyVersion(int $policyVersionId, ?array $payload, ?int $userId = null): void
    {
        $normalized = $payload === null ? null : $this->normalizeConditions($payload['conditions'] ?? [], $userId);

        DB::connection('creditcard')->transaction(function () use ($policyVersionId, $payload, $normalized): void {
            $qualification = SpendQualification::query()
                ->where('policy_version_id', $policyVersionId)
                ->first();

            if ($normalized === null) {
                $qualification?->delete();

                return;
            }

            if ($qualification === null) {
                $qualification = new SpendQualification;
                $qualification->policy_version_id = $policyVersionId;
            }

            $qualification->enabled = (bool) ($payload['enabled'] ?? true);
            $qualification->name = $this->nullableText($payload['name'] ?? null);
            $qualification->note = $this->nullableText($payload['note'] ?? null);

            // Dấu vết nguồn: CHỈ ghi khi payload chủ động gửi khoá (editor admin gửi
            // không kèm ⇒ giữ giá trị đang có; chọn mẫu ⇒ lưu lại id mẫu).
            if (array_key_exists('source_template_id', $payload)) {
                $qualification->source_template_id = ($payload['source_template_id'] === null || $payload['source_template_id'] === '')
                    ? null
                    : (int) $payload['source_template_id'];
            }

            $qualification->save();

            $this->replaceConditionRows(
                SpendQualificationCondition::class,
                SpendQualificationConditionExcludedCategory::class,
                (int) $qualification->id,
                'qualification_id',
                $normalized,
            );
        });
    }

    /**
     * Deep-clone điều kiện từ policy version nguồn sang version đích
     * (append-only tạo version N+1, clone chính sách hệ thống, lưu thành mẫu...).
     *
     * COPY TRỰC TIẾP HÀNG (đúng tinh thần `copyChildren` của PolicyCloneService):
     * phiên bản đích nhận bản copy ĐỘC LẬP hoàn toàn — conditions và excluded đều
     * là hàng MỚI, không trỏ về hàng của nguồn; dấu vết `source_template_id` được
     * giữ nguyên. Điều kiện của thẻ có thể ám chỉ category RIÊNG của người dùng
     * nên phương pháp này KHÔNG re-validate phạm vi danh mục cho từng bản copy.
     */
    public function copyFromPolicyVersion(int $sourcePolicyVersionId, int $targetPolicyVersionId): void
    {
        $source = SpendQualification::query()
            ->with('conditions.excludedCategories')
            ->where('policy_version_id', $sourcePolicyVersionId)
            ->first();

        if ($source === null) {
            return;
        }

        DB::connection('creditcard')->transaction(function () use ($source, $targetPolicyVersionId): void {
            $qualification = $this->resetQualificationForPolicyVersion($targetPolicyVersionId);
            $qualification->enabled = (bool) $source->enabled;
            $qualification->name = $source->name;
            $qualification->note = $source->note;
            $qualification->source_template_id = $source->source_template_id;
            $qualification->save();

            foreach ($source->conditions as $condition) {
                $this->copyConditionRows($condition, (int) $qualification->id);
            }
        });
    }

    /**
     * ĐÁNH GIÁ điều kiện của version cho MỘT kỳ sao kê (gate tính cashback).
     *
     * Trả về `true` = ĐẠT (hoặc không có điều kiện / bị tắt / không điều kiện
     * nào bật ⇒ no-op) ⇒ pipeline cashback tiếp tục. `false` = chưa đạt ⇒ mọi
     * giao dịch kỳ ineligible với `Transaction::REASON_QUALIFICATION_NOT_MET`.
     *
     * @param  Collection<int, Transaction>  $transactions  toàn bộ giao dịch của kỳ
     */
    public function evaluateForPeriod(PolicyVersion $version, Collection $transactions): bool
    {
        $qualification = SpendQualification::query()
            ->where('policy_version_id', $version->id)
            ->first();

        if ($qualification === null || ! $qualification->isEnabled()) {
            return true;
        }

        /** @var Collection<int, SpendQualificationCondition> $conditions */
        $conditions = $qualification->conditions()->get();

        $enabled = $conditions->filter(fn (SpendQualificationCondition $condition): bool => $condition->is_enabled);

        if ($enabled->isEmpty()) {
            return true;
        }

        $categoryTotals = $this->actualSpendByCategory($transactions);
        $totalSpend = $this->totalSpend($transactions);

        foreach ($enabled as $condition) {
            $minSpend = (float) $condition->min_spend;

            if ($condition->isCategory()) {
                $actual = (float) ($categoryTotals[(int) $condition->category_id] ?? 0.0);

                if ($actual < $minSpend) {
                    return false;
                }

                continue;
            }

            $excludedSum = 0.0;

            foreach ($condition->excludedCategories()->get() as $excluded) {
                $excludedSum += (float) ($categoryTotals[(int) $excluded->category_id] ?? 0.0);
            }

            if (($totalSpend - $excludedSum) < $minSpend) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tổng chi tiêu THỰC TẾ theo danh mục của các giao dịch (khoá = category_id).
     *
     * Không lọc eligible, không áp min_transaction_amount, không dùng tier rate —
     * đúng định nghĩa §chốt: qualification đo trên SỐ TIỀN GIAO DỊCH THỰC TẾ.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, float>
     */
    public function actualSpendByCategory(Collection $transactions): array
    {
        $totals = [];

        foreach ($transactions as $transaction) {
            $categoryId = $transaction->category_id;

            if ($categoryId === null) {
                continue;
            }

            $totals[(int) $categoryId] = ($totals[(int) $categoryId] ?? 0.0) + (float) $transaction->amount;
        }

        foreach ($totals as &$total) {
            $total = round($total, 2);
        }

        return $totals;
    }

    /**
     * Tổng chi tiêu thực tế của toàn bộ giao dịch (kể cả chưa gán danh mục).
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    public function totalSpend(Collection $transactions): float
    {
        $total = 0.0;

        foreach ($transactions as $transaction) {
            $total += (float) $transaction->amount;
        }

        return round($total, 2);
    }

    /**
     * Chuẩn hoá + kiểm tính hợp lệ danh sách điều kiện từ payload.
     *
     * Đảm bảo: type hợp lệ; `category` bắt buộc category_id + KHÔNG có excluded;
     * `other` category_id NULL + tối đa 1 + LUÔN đứng cuối; min_spend >= 0; danh
     * mục (gồm cả excluded) tồn tại và hợp lệ với phạm vi (system/user).
     *
     * @param  array<int, array<string, mixed>>|null  $conditions
     * @return array<int, array<string, mixed>>  đã sắp thứ tự chuẩn
     */
    private function normalizeConditions(?array $conditions, ?int $userId): array
    {
        $conditions = $conditions ?? [];

        // Bất biến: nhóm `category` trước (giữ thứ tự payload), `other` cuối —
        // nếu payload đặt `other` không phải cuối thì vẫn chuẩn hoá về đúng thứ
        // tự (server là nguồn sự thật, editor reload sau khi lưu).
        $categories = [];
        $other = null;

        foreach ($conditions as $condition) {
            $type = $condition['type'] ?? $condition['condition_type'] ?? null;

            if (! in_array($type, [SpendQualificationCondition::TYPE_CATEGORY, SpendQualificationCondition::TYPE_OTHER], true)) {
                throw new InvalidArgumentException('Loại điều kiện chi tiêu không hợp lệ.');
            }

            $parsed = [
                'type' => $type,
                'min_spend' => $this->minSpend($condition['min_spend'] ?? null),
                'note' => $this->nullableText($condition['note'] ?? null),
            ];

            if ($type === SpendQualificationCondition::TYPE_CATEGORY) {
                $categoryId = $condition['category_id'] ?? null;

                if ($categoryId === null || $categoryId === '') {
                    throw new InvalidArgumentException('Điều kiện danh mục phải có danh mục cụ thể.');
                }

                $excluded = $condition['excluded_category_ids'] ?? [];

                if (is_array($excluded) && $excluded !== []) {
                    throw new InvalidArgumentException('Điều kiện danh mục không được kèm danh mục loại trừ.');
                }

                $parsed['category_id'] = (int) $categoryId;
                $categories[] = $parsed;

                continue;
            }

            if (array_key_exists('category_id', $condition) && $condition['category_id'] !== null && $condition['category_id'] !== '') {
                throw new InvalidArgumentException('Điều kiện "Lĩnh vực khác" không được gắn danh mục cụ thể.');
            }

            if ($other !== null) {
                throw new InvalidArgumentException('Mỗi bộ điều kiện chỉ được có một điều kiện "Lĩnh vực khác".');
            }

            $other = $parsed + [
                'excluded_category_ids' => $this->excludedCategoryIds($condition['excluded_category_ids'] ?? [], $userId),
            ];
        }

        $allCategoryIds = collect($categories)->pluck('category_id')->merge($other['excluded_category_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($allCategoryIds !== []) {
            $this->assertUsableCategories($allCategoryIds, $userId);
        }

        $normalized = [];

        foreach ($categories as $index => $category) {
            $normalized[] = $category + ['sort_order' => $index + 1];
        }

        if ($other !== null) {
            $normalized[] = $other + ['sort_order' => count($normalized) + 1];
        }

        return $normalized;
    }

    /**
     * Danh mục được dùng trong điều kiện phải hợp lệ với phạm vi sở hữu.
     *
     * @param  array<int, int>  $categoryIds
     */
    private function assertUsableCategories(array $categoryIds, ?int $userId): void
    {
        $query = Category::query()->whereIn('id', $categoryIds)->active();

        if ($userId === null) {
            $query->system();
        } else {
            $query->where(function ($builder) use ($userId): void {
                $builder->where('scope', Category::SCOPE_SYSTEM)
                    ->orWhere(function ($owned) use ($userId): void {
                        $owned->where('scope', Category::SCOPE_USER)
                            ->where('owner_user_id', $userId);
                    });
            });
        }

        $found = $query->pluck('id')->map(fn ($id) => (int) $id);

        $missing = collect($categoryIds)->diff($found);

        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException('Danh mục trong điều kiện chi tiêu không hợp lệ hoặc không thuộc phạm vi của bạn (#'.$missing->implode(', #').').');
        }
    }

    /**
     * @param  array<int>|mixed  $list
     * @return array<int, int>
     */
    private function excludedCategoryIds($list, ?int $userId): array
    {
        if ($list === null || $list === '') {
            return [];
        }

        if (! is_array($list)) {
            throw new InvalidArgumentException('Danh sách danh mục loại trừ không hợp lệ.');
        }

        $ids = [];

        foreach ($list as $id) {
            if ($id === null || $id === '') {
                continue;
            }

            $parsed = (int) $id;

            if (in_array($parsed, $ids, true)) {
                throw new InvalidArgumentException('Danh mục loại trừ không được trùng lặp.');
            }

            $ids[] = $parsed;
        }

        if ($ids !== []) {
            $this->assertUsableCategories($ids, $userId);
        }

        return $ids;
    }

    /**
     * Thay toàn bộ điều kiện của template bằng danh sách đã chuẩn hoá.
     *
     * @param  array<int, array<string, mixed>>|null  $normalized
     */
    private function replaceTemplateConditions(int $templateId, ?array $normalized): void
    {
        SpendQualificationTemplateConditionExcludedCategory::query()
            ->whereIn('condition_id', SpendQualificationTemplateCondition::query()->where('template_id', $templateId)->select('id'))
            ->delete();

        SpendQualificationTemplateCondition::query()->where('template_id', $templateId)->delete();

        if ($normalized === null) {
            return;
        }

        $this->writeConditionRows(
            SpendQualificationTemplateCondition::class,
            SpendQualificationTemplateConditionExcludedCategory::class,
            $templateId,
            'template_id',
            $normalized,
        );
    }

    /**
     * Thay toàn bộ điều kiện của MỘT qualification (policy version).
     *
     * @param  array<int, array<string, mixed>>  $normalized
     */
    private function replaceConditionRows(
        string $conditionClass,
        string $excludedClass,
        int $parentId,
        string $parentKey,
        array $normalized,
    ): void {
        $excludedClass::query()
            ->whereIn('condition_id', $conditionClass::query()->where($parentKey, $parentId)->select('id'))
            ->delete();

        $conditionClass::query()->where($parentKey, $parentId)->delete();

        $this->writeConditionRows($conditionClass, $excludedClass, $parentId, $parentKey, $normalized);
    }

    /**
     * @param  array<int, array<string, mixed>>  $normalized
     */
    private function writeConditionRows(
        string $conditionClass,
        string $excludedClass,
        int $parentId,
        string $parentKey,
        array $normalized,
    ): void {
        foreach ($normalized as $row) {
            $condition = new $conditionClass;
            $condition->forceFill([
                $parentKey => $parentId,
                'condition_type' => $row['type'],
                'category_id' => $row['type'] === SpendQualificationCondition::TYPE_CATEGORY
                    ? $row['category_id']
                    : null,
                'min_spend' => $row['min_spend'],
                'is_enabled' => true,
                'sort_order' => $row['sort_order'],
                'note' => $row['note'],
            ]);
            $condition->save();

            if ($row['type'] === SpendQualificationCondition::TYPE_OTHER) {
                foreach ($row['excluded_category_ids'] as $categoryId) {
                    $excluded = new $excludedClass;
                    $excluded->forceFill([
                        'condition_id' => $condition->id,
                        'category_id' => $categoryId,
                    ]);
                    $excluded->save();
                }
            }
        }
    }

    /**
     * Đặt lại qualification của một version đích (xoá bản cũ nếu có) để sẵn sàng
     * nhận bản copy mới — đảm bảo không bao giờ có hai bản ghi cho cùng version.
     */
    private function resetQualificationForPolicyVersion(int $policyVersionId): SpendQualification
    {
        SpendQualification::query()
            ->where('policy_version_id', $policyVersionId)
            ->delete();

        $qualification = new SpendQualification;
        $qualification->policy_version_id = $policyVersionId;
        $qualification->enabled = true;
        $qualification->save();

        return $qualification;
    }

    /**
     * Nhân bản MỘT condition (kèm danh mục loại trừ) sang qualification đích.
     *
     * Nhận cả `SpendQualificationCondition` lẫn `SpendQualificationTemplateCondition`
     * vì hai khối này đối xứng nhau — cùng lược đồ cột, khác bài toán trừ.
     */
    private function copyConditionRows($condition, int $targetQualificationId): void
    {
        $copy = new SpendQualificationCondition;
        $copy->forceFill([
            'qualification_id' => $targetQualificationId,
            'condition_type' => $condition->condition_type,
            'category_id' => $condition->category_id,
            'min_spend' => $condition->min_spend,
            'is_enabled' => (bool) $condition->is_enabled,
            'sort_order' => (int) $condition->sort_order,
            'note' => $condition->note,
        ]);
        $copy->save();

        foreach ($condition->excludedCategories as $excluded) {
            $excludedCopy = new SpendQualificationConditionExcludedCategory;
            $excludedCopy->forceFill([
                'condition_id' => $copy->id,
                'category_id' => (int) $excluded->category_id,
            ]);
            $excludedCopy->save();
        }
    }

    /**
     * @param  Collection<int, SpendQualificationCondition|SpendQualificationTemplateCondition>  $conditions
     * @return array{enabled: bool, name: string|null, note: string|null, conditions: array<int, array<string, mixed>>}
     */
    private function serializeConditions(Collection $conditions): array
    {
        return [
            'enabled' => true,
            'name' => null,
            'note' => null,
            'conditions' => $conditions->map(function ($condition): array {
                $data = [
                    'id' => (int) $condition->id,
                    'type' => $condition->condition_type,
                    'category_id' => $condition->category_id,
                    'category_name' => $condition->category?->name,
                    'min_spend' => (float) $condition->min_spend,
                    'sort_order' => (int) $condition->sort_order,
                    'note' => $condition->note,
                ];

                if ($condition->isOther()) {
                    $data['excluded_category_ids'] = $condition->excludedCategories
                        ->pluck('category_id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all();
                }

                return $data;
            })->values()->all(),
        ];
    }

    private function minSpend(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException('Điều kiện chi tiêu phải có số tiền tối thiểu.');
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new InvalidArgumentException('Số tiền tối thiểu của điều kiện chi tiêu không hợp lệ.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}