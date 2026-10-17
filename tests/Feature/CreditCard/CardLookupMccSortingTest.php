<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccProfileRowCode;
use App\Models\CreditCard\PolicyMccRowBenefit;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Thứ tự kết quả "Tra cứu MCC" (tab Tra cứu MCC).
 *
 * Quy tắc bắt buộc: sắp theo dữ liệu cashback THỰC TẾ —
 *   1. tỷ lệ hoàn tiền cao nhất trước (so sánh BẰNG SỐ);
 *   2. cùng tỷ lệ ⇒ trần hoàn tiền cao hơn trước (NULL xếp sau trần số);
 *   3. hoà hoàn toàn ⇒ tên chính sách rồi id (thứ tự ổn định);
 *   4. thẻ thiếu tỷ lệ số xếp sau thẻ so sánh được; loại trừ xếp cuối.
 *
 * Một thẻ (hồ sơ/ sản phẩm dùng chung) KHÔNG được xuất hiện trùng. Thứ tự này
 * KHÔNG đổi dữ liệu cashback/MCC và KHÔNG áp cho tab "Tra cứu danh mục".
 */
class CardLookupMccSortingTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();
    }

    private function seedMcc(string $code): void
    {
        MccCode::create([
            'mcc' => $code,
            'description_vi' => 'Mô tả '.$code,
            'verification_status' => MccCode::STATUS_UNVERIFIED,
        ]);
    }

    /**
     * Hồ sơ gồm nhiều dòng (included/excluded), mỗi dòng có mã và bậc tùy chọn.
     *
     * @param  array<int, array{type:string, category:string, codes:array<int,string>, tiers?:array<int, array<string,mixed>>}>  $rows
     * @param  array<string, mixed>  $attributes
     */
    private function makeProfileWithRows(PolicyTemplate $template, array $rows, array $attributes = []): PolicyMccProfile
    {
        $profile = PolicyMccProfile::create(array_merge([
            'policy_template_id' => $template->id,
            'name' => 'MCC '.$template->name,
        ], $attributes));

        foreach ($rows as $definition) {
            $row = PolicyMccProfileRow::create([
                'policy_mcc_profile_id' => $profile->id,
                'row_type' => $definition['type'],
                'category_name' => $definition['category'],
            ]);

            foreach ($definition['codes'] as $code) {
                PolicyMccProfileRowCode::create([
                    'policy_mcc_row_id' => $row->id,
                    'mcc' => $code,
                ]);
            }

            foreach (array_values($definition['tiers'] ?? []) as $order => $tier) {
                PolicyMccRowBenefit::create(array_merge([
                    'policy_mcc_row_id' => $row->id,
                    'tier_order' => $order,
                ], $tier));
            }
        }

        return $profile;
    }

    /**
     * Một thẻ = một template + một hồ sơ included chứa mã, kèm các bậc.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @param  array<string, mixed>  $attributes
     */
    private function makeCard(string $templateName, string $mcc, array $tiers, array $attributes = []): PolicyMccProfile
    {
        $template = $this->makeSystemTemplate(['name' => $templateName]);

        return $this->makeProfileWithRows($template, [
            ['type' => PolicyMccProfileRow::TYPE_INCLUDED, 'category' => 'Danh mục', 'codes' => [$mcc], 'tiers' => $tiers],
        ], $attributes);
    }

    /**
     * @return array<int, string>
     */
    private function policyNames(array $matches): array
    {
        return array_map(fn (array $match): string => (string) $match['policy_name'], $matches);
    }

    private function lookup(string $mcc, User $user): array
    {
        return $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => $mcc]))
            ->assertOk()
            ->json('data.matches');
    }

    #[Test]
    public function lookup_mcc_orders_by_highest_rate_then_cap_descending(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Bảng ví dụ: tỷ lệ giảm dần, cùng tỷ lệ thì trần cao hơn trước.
        $this->makeCard('Thẻ A', '5411', [['cashback_percent' => '20.000', 'cap_amount' => '300000']]);
        $this->makeCard('Thẻ B', '5411', [['cashback_percent' => '20.000', 'cap_amount' => '200000']]);
        $this->makeCard('Thẻ C', '5411', [['cashback_percent' => '15.000', 'cap_amount' => '500000']]);
        $this->makeCard('Thẻ D', '5411', [['cashback_percent' => '15.000', 'cap_amount' => '100000']]);
        $this->makeCard('Thẻ E', '5411', [['cashback_percent' => '10.000', 'cap_amount' => '400000']]);
        $this->makeCard('Thẻ F', '5411', [['cashback_percent' => '0.500']]);

        $this->assertSame(
            ['Thẻ A', 'Thẻ B', 'Thẻ C', 'Thẻ D', 'Thẻ E', 'Thẻ F'],
            $this->policyNames($this->lookup('5411', $user)),
        );
    }

    #[Test]
    public function lookup_mcc_compares_rates_numerically_not_lexicographically(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // So sánh chuỗi giảm dần sẽ đặt "9.000" trước "10.000" (sai). Phải theo số.
        $this->makeCard('Thẻ hai phẩy năm', '5411', [['cashback_percent' => '2.500']]);
        $this->makeCard('Thẻ chín', '5411', [['cashback_percent' => '9.000']]);
        $this->makeCard('Thẻ mười', '5411', [['cashback_percent' => '10.000']]);

        $this->assertSame(
            ['Thẻ mười', 'Thẻ chín', 'Thẻ hai phẩy năm'],
            $this->policyNames($this->lookup('5411', $user)),
        );
    }

    #[Test]
    public function lookup_mcc_keeps_higher_rate_before_lower_rate_even_with_smaller_cap(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // 20% trần 100k phải đứng trước 15% trần 900k — tỷ lệ thắng trần.
        $this->makeCard('Thẻ hai mươi', '5411', [['cashback_percent' => '20.000', 'cap_amount' => '100000']]);
        $this->makeCard('Thẻ mười lăm', '5411', [['cashback_percent' => '15.000', 'cap_amount' => '900000']]);

        $this->assertSame(
            ['Thẻ hai mươi', 'Thẻ mười lăm'],
            $this->policyNames($this->lookup('5411', $user)),
        );
    }

    #[Test]
    public function lookup_mcc_sorts_stably_by_name_when_rate_and_cap_tie(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Cùng tỷ lệ + cùng trần: thứ tự theo tên, ổn định giữa các lần truy vấn.
        $this->makeCard('Thẻ Z', '5411', [['cashback_percent' => '5.000', 'cap_amount' => '200000']]);
        $this->makeCard('Thẻ T', '5411', [['cashback_percent' => '5.000', 'cap_amount' => '200000']]);

        $first = $this->policyNames($this->lookup('5411', $user));
        $second = $this->policyNames($this->lookup('5411', $user));

        $this->assertSame(['Thẻ T', 'Thẻ Z'], $first);
        $this->assertSame($first, $second);
    }

    #[Test]
    public function lookup_mcc_null_cap_sorts_after_numeric_cap_at_same_rate(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Trần NULL = nguồn không nêu, KHÔNG coi là 0đ và KHÔNG là "không giới hạn":
        // xếp sau bậc có trần số khi cùng tỷ lệ.
        $this->makeCard('Thẻ không trần', '5411', [['cashback_percent' => '5.000']]);
        $this->makeCard('Thẻ có trần', '5411', [['cashback_percent' => '5.000', 'cap_amount' => '150000']]);

        $this->assertSame(
            ['Thẻ có trần', 'Thẻ không trần'],
            $this->policyNames($this->lookup('5411', $user)),
        );
    }

    #[Test]
    public function lookup_mcc_ranks_missing_numeric_rate_after_rated_cards(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Thẻ chỉ có nhãn ("tới 20%") không có tỷ lệ số ⇒ xếp sau thẻ có 0,5% số.
        $this->makeCard('Thẻ nhãn', '5411', [['rate_label' => 'tới 20%', 'cap_amount' => '500000']]);
        $this->makeCard('Thẻ nửa phần trăm', '5411', [['cashback_percent' => '0.500']]);

        $this->assertSame(
            ['Thẻ nửa phần trăm', 'Thẻ nhãn'],
            $this->policyNames($this->lookup('5411', $user)),
        );
    }

    #[Test]
    public function lookup_mcc_puts_excluded_results_after_applicable_cards(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        $excludedTemplate = $this->makeSystemTemplate(['name' => 'Thẻ loại trừ']);
        $this->makeProfileWithRows($excludedTemplate, [
            ['type' => PolicyMccProfileRow::TYPE_EXCLUDED, 'category' => 'Loại trừ', 'codes' => ['5411']],
        ]);

        $this->makeCard('Thẻ hoàn tiền', '5411', [['cashback_percent' => '0.500']]);

        $this->assertSame(
            ['Thẻ hoàn tiền', 'Thẻ loại trừ'],
            $this->policyNames($this->lookup('5411', $user)),
        );
    }

    #[Test]
    public function lookup_mcc_merges_multiple_matching_rows_of_one_profile_into_one_result(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // CÙNG một hồ sơ có HAI dòng included đều chứa 5411 ⇒ MỘT kết quả,
        // giữ tỷ lệ cao nhất (8%) và hợp nhất bậc của cả hai dòng.
        $template = $this->makeSystemTemplate(['name' => 'Thẻ gộp dòng']);
        $this->makeProfileWithRows($template, [
            ['type' => PolicyMccProfileRow::TYPE_INCLUDED, 'category' => 'Mua sắm', 'codes' => ['5411'],
                'tiers' => [['cashback_percent' => '3.000', 'cap_amount' => '100000']]],
            ['type' => PolicyMccProfileRow::TYPE_INCLUDED, 'category' => 'Ăn uống', 'codes' => ['5411'],
                'tiers' => [['cashback_percent' => '8.000', 'cap_amount' => '300000']]],
        ]);

        $matches = $this->lookup('5411', $user);

        $this->assertCount(1, $matches);
        $this->assertSame('Thẻ gộp dòng', $matches[0]['policy_name']);
        $this->assertSame('8.000', $matches[0]['cashback']['highest']['cashback_percent']);
        $this->assertCount(2, $matches[0]['cashback']['tiers']);
        $this->assertStringContainsString('Mua sắm', (string) $matches[0]['category_name']);
        $this->assertStringContainsString('Ăn uống', (string) $matches[0]['category_name']);
    }

    #[Test]
    public function lookup_mcc_merges_duplicate_excluded_rows_of_one_profile_into_one_result(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        $template = $this->makeSystemTemplate(['name' => 'Thẻ loại trừ hai dòng']);
        $this->makeProfileWithRows($template, [
            ['type' => PolicyMccProfileRow::TYPE_EXCLUDED, 'category' => 'Loại trừ 1', 'codes' => ['5411']],
            ['type' => PolicyMccProfileRow::TYPE_EXCLUDED, 'category' => 'Loại trừ 2', 'codes' => ['5411']],
        ]);

        $matches = $this->lookup('5411', $user);

        $this->assertCount(1, $matches);
        $this->assertSame('excluded', $matches[0]['relation']);
        $this->assertSame('excluded', $matches[0]['cashback']['status']);
    }

    #[Test]
    public function lookup_mcc_shared_product_group_sorts_by_its_highest_rate_once(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5252');

        // Sản phẩm dùng chung nhiều hạng: cao nhất 10% ⇒ đứng sau thẻ 15%, trước
        // thẻ 10,5%, và chỉ xuất hiện MỘT kết quả.
        $this->makeCard(
            'VIB One Card Visa - Gold',
            '5252',
            [['cashback_percent' => '6.000']],
            ['product_name' => 'VIB One Card Visa'],
        );
        $this->makeCard(
            'VIB One Card Visa - Signature',
            '5252',
            [['cashback_percent' => '10.000']],
            ['product_name' => 'VIB One Card Visa'],
        );
        $this->makeCard('Thẻ mười lăm', '5252', [['cashback_percent' => '15.000']]);
        $this->makeCard('Thẻ mười phẩy năm', '5252', [['cashback_percent' => '10.500']]);

        $this->assertSame(
            ['Thẻ mười lăm', 'Thẻ mười phẩy năm', 'VIB One Card Visa'],
            $this->policyNames($this->lookup('5252', $user)),
        );
    }
}
