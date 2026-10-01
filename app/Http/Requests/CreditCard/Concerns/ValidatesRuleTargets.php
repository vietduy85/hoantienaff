<?php

namespace App\Http\Requests\CreditCard\Concerns;

use App\Models\CreditCard\Category;
use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\PolicyTierCategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validate TARGET của một quy tắc cashback: DANH MỤC | COMBO | FALLBACK.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO KHÔNG DÙNG `required_unless` ĐƯỢC
 * ---------------------------------------------------------------------------
 * Target có BA trạng thái, không phải hai:
 *
 *   1. rule danh mục : `scope_type=category` + `category_id` có  + `combo_id` NULL
 *   2. rule combo    : `scope_type=category` + `combo_id` CÓ     + `category_id` NULL
 *   3. fallback      : `scope_type=other`    + cả hai target đều NULL
 *
 * `required_unless:scope_type,other` chỉ diễn đạt được "trừ case fallback" nên
 * ép `category_id` bắt buộc cho case combo — sai. Và nó KHÔNG bắt được lỗi
 * nguy hiểm nhất: gửi CẢ `category_id` lẫn `combo_id` cùng lúc. Tầng service chặn
 * việc đó bằng `LogicException`, nhưng exception bay lên thành HTTP 500. Validate ở
 * tầng request để trả 422 kèm đúng field ⇒ UI báo lỗi được ngay.
 *
 * ---------------------------------------------------------------------------
 * PHẠM VI
 * ---------------------------------------------------------------------------
 * - `ruleTargetRules()`: bộ rule khai báo cho 3 field target.
 * - `assertSingleTarget()`: kiểm tra 1 rule phẳng (API rule của user).
 * - `assertNestedTargets()`: kiểm tra các rule lồng trong `tiers[].rules[]`
 *   (màn System Policy editor).
 */
trait ValidatesRuleTargets
{
    /**
     * Bộ rule khai báo cho target. KHÔNG `required` ở đây vì case fallback không
     * có target nào — bắt buộc "đúng một trong ba" do `assert*` đảm nhiệm.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function ruleTargetRules(): array
    {
        return [
            'scope_type' => [
                'sometimes',
                Rule::in([PolicyTierCategory::SCOPE_CATEGORY, PolicyTierCategory::SCOPE_OTHER]),
            ],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'combo_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * Kiểm tra target của MỘT rule phẳng (`$key` = prefix field, ví dụ `''`).
     *
     * @param  int|null  $userId  null = phía admin (chỉ combo/danh mục hệ thống);
     *                            có id = phía user (system + của chính mình).
     */
    protected function assertSingleTarget(Validator $validator, ?int $userId = null, string $prefix = ''): void
    {
        $validator->after(function (Validator $validator) use ($userId, $prefix): void {
            $scope = $this->ruleTargetInput('scope_type', $prefix) ?? PolicyTierCategory::SCOPE_CATEGORY;
            $categoryId = $this->ruleTargetInput('category_id', $prefix);
            $comboId = $this->ruleTargetInput('combo_id', $prefix);

            $this->assertTargetCombination($validator, $scope, $categoryId, $comboId, $prefix, $userId);
        });
    }

    /**
     * Kiểm tra target của các rule lồng trong `tiers[].rules[]`.
     */
    protected function assertNestedTargets(Validator $validator, ?int $userId = null): void
    {
        $validator->after(function (Validator $validator) use ($userId): void {
            $tiers = $this->input('tiers');

            if (! is_array($tiers)) {
                return;
            }

            foreach ($tiers as $i => $tier) {
                if (! is_array($tier) || ! is_array($tier['rules'] ?? null)) {
                    continue;
                }

                foreach ($tier['rules'] as $j => $rule) {
                    if (! is_array($rule)) {
                        continue;
                    }

                    $prefix = "tiers.{$i}.rules.{$j}.";

                    $this->assertTargetCombination(
                        $validator,
                        $rule['scope_type'] ?? PolicyTierCategory::SCOPE_CATEGORY,
                        $rule['category_id'] ?? null,
                        $rule['combo_id'] ?? null,
                        $prefix,
                        $userId
                    );
                }
            }
        });
    }

    private function assertTargetCombination(
        Validator $validator,
        mixed $scope,
        mixed $categoryId,
        mixed $comboId,
        string $prefix,
        ?int $userId
    ): void {
        $scope = is_string($scope) ? $scope : PolicyTierCategory::SCOPE_CATEGORY;
        $categoryId = $this->normaliseId($categoryId);
        $comboId = $this->normaliseId($comboId);

        // Fallback: target bị ÉP NULL, KHÔNG phải lỗi.
        //
        // Editor gửi `category_id: null` cho fallback, nhưng client cũ/script tay có
        // thể vô tình kèm theo. `CategoryRuleService` đã ép null cả hai target khi
        // `scope_type = other`, và hành vi đó được test khoá lại
        // (`a_fallback_payload_carrying_a_category_id_is_force_nulled`). Ở đây phải
        // im lặng theo đúng contract đó, không trả 422.
        if ($scope === PolicyTierCategory::SCOPE_OTHER) {
            return;
        }

        // Loại trừ lẫn nhau — chặn trước để không có request nào tạo được rule
        // mơ hồ (service chặn bằng LogicException ⇒ HTTP 500 nếu lọt xuống đó).
        if ($categoryId !== null && $comboId !== null) {
            $validator->errors()->add(
                $prefix.'combo_id',
                'Một quy tắc chỉ gắn MỘT mục tiêu: hoặc danh mục, hoặc combo — không được cả hai.'
            );

            return;
        }

        if ($comboId !== null) {
            $this->assertComboUsable($validator, $comboId, $prefix, $userId);

            return;
        }

        if ($categoryId !== null) {
            $this->assertCategoryUsable($validator, $categoryId, $prefix, $userId);

            return;
        }

        $validator->errors()->add(
            $prefix.'category_id',
            'Vui lòng chọn danh mục, combo, hoặc để quy tắc làm mặc định.'
        );
    }

    /**
     * Kiểm tra combo user được phép dùng.
     *
     * Phía admin (`$userId === null`) CỐ Ý không kiểm ở tầng request: thông báo
     * "combo hệ thống" của `CategoryRuleService` rõ hơn, và request không được
     * làm hỏng contract 422 + message mà các test đang khoá.
     */
    private function assertComboUsable(Validator $validator, int $comboId, string $prefix, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        if (! CategoryCombo::query()->selectableBy($userId)->whereKey($comboId)->exists()) {
            $validator->errors()->add(
                $prefix.'combo_id',
                'Combo không hợp lệ hoặc bạn không được sử dụng.'
            );
        }
    }

    /**
     * Kiểm tra danh mục user được phép dùng (giữ nguyên thông báo cũ).
     *
     * Phía admin không kiểm ở đây — xem `assertComboUsable()`.
     */
    private function assertCategoryUsable(Validator $validator, int $categoryId, string $prefix, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        if (! Category::query()->selectableBy($userId)->whereKey($categoryId)->exists()) {
            $validator->errors()->add(
                $prefix.'category_id',
                'Danh mục không hợp lệ hoặc bạn không được sử dụng.'
            );
        }
    }

    private function ruleTargetInput(string $field, string $prefix): mixed
    {
        return $this->input($prefix === '' ? $field : $prefix.$field);
    }

    private function normaliseId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            // Không phải id hợp lệ: trả về 0 để `assert*` báo lỗi tồn tại
            // (không âm thầm coi như "không gửi").
            return 0;
        }

        return (int) $value;
    }
}
