<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\MccCode;
use App\Models\CreditCard\PolicyMccProfile;
use App\Models\CreditCard\PolicyMccProfileRow;
use App\Models\CreditCard\PolicyMccProfileRowCode;
use App\Models\CreditCard\PolicyMccRowBenefit;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Phân biệt "cashback CHÍNH THỨC" và "ƯU ĐÃI BỔ SUNG" (bonus) ở CẢ HAI tab
 * "Tra cứu MCC" và "Tra cứu danh mục".
 *
 * Quy tắc: xếp hạng + hiển thị "Hoàn tiền chính thức" CHỈ dựa trên tỷ lệ chính
 * thức; ưu đãi bổ sung (khuyến mại/kích hoạt/giao dịch đầu tiên) được hiển thị
 * RIÊNG, KHÔNG dùng làm tỷ lệ xếp hạng. Bậc không có dấu phân loại mặc định là
 * chính thức (không suy diễn theo tỷ lệ cao bất thường).
 *
 * Dấu phân loại nằm ở cột `source_note` của bậc (`'bonus'` = ưu đãi bổ sung).
 */
class CardLookupBenefitKindTest extends TestCase
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
     * Một thẻ cho tab "Tra cứu MCC": template + hồ sơ có một dòng included chứa mã.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     * @param  array<string, mixed>  $attributes
     */
    private function makeMccCard(string $templateName, string $mcc, array $tiers, array $attributes = []): PolicyMccProfile
    {
        $template = $this->makeSystemTemplate(['name' => $templateName]);

        $profile = PolicyMccProfile::create(array_merge([
            'policy_template_id' => $template->id,
            'name' => 'MCC '.$templateName,
        ], $attributes));

        $row = PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_INCLUDED,
            'category_name' => 'Danh mục',
        ]);

        PolicyMccProfileRowCode::create([
            'policy_mcc_row_id' => $row->id,
            'mcc' => $mcc,
        ]);

        foreach (array_values($tiers) as $order => $tier) {
            PolicyMccRowBenefit::create(array_merge([
                'policy_mcc_row_id' => $row->id,
                'tier_order' => $order,
            ], $tier));
        }

        return $profile;
    }

    /**
     * Một thẻ cho tab "Tra cứu danh mục": hồ sơ có một dòng included thuộc danh mục.
     *
     * @param  array<int, array<string, mixed>>  $benefits
     * @param  array<string, mixed>  $attributes
     */
    private function makeCategoryCard(string $templateName, string $categoryName, array $benefits, array $attributes = []): PolicyMccProfile
    {
        $template = $this->makeSystemTemplate(['name' => $templateName]);

        $profile = PolicyMccProfile::create(array_merge([
            'policy_template_id' => $template->id,
            'name' => 'Hồ sơ '.$templateName,
        ], $attributes));

        $row = PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_INCLUDED,
            'category_name' => $categoryName,
        ]);

        foreach (array_values($benefits) as $order => $tier) {
            PolicyMccRowBenefit::create(array_merge([
                'policy_mcc_row_id' => $row->id,
                'tier_order' => $order,
            ], $tier));
        }

        return $profile;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mccLookup(string $mcc, User $user): array
    {
        return $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.mcc', ['mcc' => $mcc]))
            ->assertOk()
            ->json('data.matches');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categoryLookup(string $category, User $user): array
    {
        return $this->actingAs($user)
            ->getJson(route('credit-cards.lookup.category', ['category' => $category]))
            ->assertOk()
            ->json('data.matches');
    }

    /**
     * @return array<int, string>
     */
    private function policyNames(array $matches): array
    {
        return array_map(fn (array $match): string => (string) $match['policy_name'], $matches);
    }

    private function bonusTier(float $pct, ?float $cap = null, string $condition = 'Giao dịch đầu tiên trong 30 ngày kích hoạt'): array
    {
        return array_filter([
            'cashback_percent' => number_format($pct, 3, '.', ''),
            'cap_amount' => $cap === null ? null : number_format($cap, 2, '.', ''),
            'condition_note' => $condition,
            'source_note' => 'bonus',
        ], fn ($value): bool => $value !== null);
    }

    private function officialTier(float $pct, ?float $cap = null, string $condition = 'Các giao dịch còn lại'): array
    {
        return array_filter([
            'cashback_percent' => number_format($pct, 3, '.', ''),
            'cap_amount' => $cap === null ? null : number_format($cap, 2, '.', ''),
            'condition_note' => $condition,
        ], fn ($value): bool => $value !== null);
    }

    // =====================================================================
    // Tab "Tra cứu MCC"
    // =====================================================================

    #[Test]
    public function mcc_bonus_tier_does_not_become_official_rate(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Sacombank UniQ: 50% giao dịch đầu (bonus) + 20% giao dịch còn lại (chính thức).
        $this->makeMccCard('Sacombank UniQ', '5411', [
            $this->bonusTier(50, 300000),
            $this->officialTier(20, 300000),
        ]);

        $matches = $this->mccLookup('5411', $user);
        $cashback = $matches[0]['cashback'];

        $this->assertSame('20.000', $cashback['highest']['cashback_percent']);
        $this->assertSame('Các giao dịch còn lại', $cashback['highest']['note']);
        $this->assertSame('50.000', $cashback['highest_bonus']['cashback_percent']);
    }

    #[Test]
    public function mcc_ranking_uses_official_rate_not_bonus(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Thẻ A: 10% chính thức. Thẻ B: bonus 50% nhưng chính thức chỉ 5%.
        // Thẻ A phải xếp trước vì tỷ lệ CHÍNH THỨC cao hơn.
        $this->makeMccCard('Thẻ A', '5411', [$this->officialTier(10, 500000)]);
        $this->makeMccCard('Thẻ B', '5411', [$this->bonusTier(50, 999000), $this->officialTier(5, 100000)]);

        $this->assertSame(['Thẻ A', 'Thẻ B'], $this->policyNames($this->mccLookup('5411', $user)));
    }

    #[Test]
    public function mcc_card_with_only_bonus_has_no_official_rate_and_ranks_after_rated(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        $this->makeMccCard('Thẻ chính thức', '5411', [$this->officialTier(3, 50000)]);
        $this->makeMccCard('Thẻ chỉ bonus', '5411', [$this->bonusTier(50, 300000)]);

        $matches = $this->mccLookup('5411', $user);

        $this->assertSame(['Thẻ chính thức', 'Thẻ chỉ bonus'], $this->policyNames($matches));
        $this->assertNull($matches[1]['cashback']['highest']);
        $this->assertSame('50.000', $matches[1]['cashback']['highest_bonus']['cashback_percent']);
    }

    #[Test]
    public function mcc_partitions_official_and_bonus_tiers(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        $this->makeMccCard('Thẻ tách loại', '5411', [
            $this->bonusTier(50, 300000),
            $this->officialTier(20, 300000),
        ]);

        $cashback = $this->mccLookup('5411', $user)[0]['cashback'];

        // Toàn bộ bậc vẫn được giữ, nhưng tách rõ hai nhóm.
        $this->assertCount(2, $cashback['tiers']);
        $this->assertCount(1, $cashback['official_tiers']);
        $this->assertCount(1, $cashback['bonus_tiers']);
        $this->assertFalse($cashback['official_tiers'][0]['is_bonus']);
        $this->assertTrue($cashback['bonus_tiers'][0]['is_bonus']);
        $this->assertSame('official', $cashback['official_tiers'][0]['benefit_type']);
        $this->assertSame('bonus', $cashback['bonus_tiers'][0]['benefit_type']);
    }

    #[Test]
    public function mcc_unclassified_tier_is_treated_as_official(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Không có dấu phân loại ⇒ KHÔNG tự suy diễn là bonus, coi là chính thức.
        $this->makeMccCard('Thẻ thường', '5411', [['cashback_percent' => '5.000']]);

        $cashback = $this->mccLookup('5411', $user)[0]['cashback'];

        $this->assertSame('5.000', $cashback['highest']['cashback_percent']);
        $this->assertNull($cashback['highest_bonus']);
        $this->assertFalse($cashback['official_tiers'][0]['is_bonus']);
    }

    #[Test]
    public function mcc_bonus_cap_does_not_win_the_cap_tiebreak(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Cùng tỷ lệ chính thức 10%: thẻ có trần CHÍNH THỨC cao hơn phải đứng
        // trước, dù thẻ kia có trần BONUS khổng lồ.
        $this->makeMccCard('Thẻ bonus trần lớn', '5411', [
            $this->bonusTier(50, 999000000),
            $this->officialTier(10, 100000),
        ]);
        $this->makeMccCard('Thẻ trần chính thức lớn', '5411', [
            $this->officialTier(10, 500000),
        ]);

        $this->assertSame(
            ['Thẻ trần chính thức lớn', 'Thẻ bonus trần lớn'],
            $this->policyNames($this->mccLookup('5411', $user)),
        );
    }

    #[Test]
    public function mcc_merged_rows_keep_bonus_classification(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // MỘT hồ sơ có HAI dòng included chứa 5411: dòng 1 bonus 50%, dòng 2
        // chính thức 20% ⇒ MỘT kết quả, cao nhất chính thức = 20%.
        $template = $this->makeSystemTemplate(['name' => 'Thẻ gộp']);
        $profile = PolicyMccProfile::create([
            'policy_template_id' => $template->id,
            'name' => 'MCC Thẻ gộp',
        ]);

        $rowA = PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_INCLUDED,
            'category_name' => 'Siêu thị',
        ]);
        PolicyMccProfileRowCode::create(['policy_mcc_row_id' => $rowA->id, 'mcc' => '5411']);
        PolicyMccRowBenefit::create(array_merge(['policy_mcc_row_id' => $rowA->id, 'tier_order' => 0], $this->bonusTier(50, 300000)));

        $rowB = PolicyMccProfileRow::create([
            'policy_mcc_profile_id' => $profile->id,
            'row_type' => PolicyMccProfileRow::TYPE_INCLUDED,
            'category_name' => 'Ứng dụng di chuyển',
        ]);
        PolicyMccProfileRowCode::create(['policy_mcc_row_id' => $rowB->id, 'mcc' => '5411']);
        PolicyMccRowBenefit::create(array_merge(['policy_mcc_row_id' => $rowB->id, 'tier_order' => 0], $this->officialTier(20, 300000)));

        $matches = $this->mccLookup('5411', $user);

        $this->assertCount(1, $matches);
        $this->assertSame('20.000', $matches[0]['cashback']['highest']['cashback_percent']);
        $this->assertSame('50.000', $matches[0]['cashback']['highest_bonus']['cashback_percent']);
    }

    #[Test]
    public function mcc_shared_product_ranks_by_official_highest_rate(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5252');

        // Sản phẩm dùng chung: hạng Starter chỉ bonus 50%; hạng Plus chính thức 12%.
        // Xếp hạng theo 12% (chính thức), KHÔNG theo 50% bonus.
        $this->makeMccCard('Thẻ dùng chung - Starter', '5252', [$this->bonusTier(50, 999000)], ['product_name' => 'Thẻ dùng chung']);
        $this->makeMccCard('Thẻ dùng chung - Plus', '5252', [$this->officialTier(12, 300000)], ['product_name' => 'Thẻ dùng chung']);
        $this->makeMccCard('Thẻ mười lăm', '5252', [$this->officialTier(15, 400000)]);

        $this->assertSame(
            ['Thẻ mười lăm', 'Thẻ dùng chung'],
            $this->policyNames($this->mccLookup('5252', $user)),
        );
    }

    // =====================================================================
    // Tab "Tra cứu danh mục"
    // =====================================================================

    #[Test]
    public function category_bonus_tier_does_not_become_official_rate(): void
    {
        $user = User::factory()->create();

        $this->makeCategoryCard('Sacombank UniQ', 'Siêu thị', [
            $this->bonusTier(50, 300000),
            $this->officialTier(20, 300000),
        ]);

        $cashback = $this->categoryLookup('supermarket', $user)[0]['cashback'];

        $this->assertSame('20.000', $cashback['highest']['cashback_percent']);
        $this->assertSame('50.000', $cashback['highest_bonus']['cashback_percent']);
    }

    #[Test]
    public function category_ranking_uses_official_rate_not_bonus(): void
    {
        $user = User::factory()->create();

        $this->makeCategoryCard('Thẻ A', 'Siêu thị', [$this->officialTier(10, 500000)]);
        $this->makeCategoryCard('Thẻ B', 'Siêu thị', [$this->bonusTier(50, 999000), $this->officialTier(5, 100000)]);

        $this->assertSame(['Thẻ A', 'Thẻ B'], $this->policyNames($this->categoryLookup('supermarket', $user)));
    }

    #[Test]
    public function category_is_sorted_by_official_cashback_descending(): void
    {
        $user = User::factory()->create();

        // Tên ngược thứ tự tỷ lệ ⇒ chứng minh sắp theo cashback chứ không theo tên.
        $this->makeCategoryCard('A thấp', 'Siêu thị', [$this->officialTier(2, 200000)]);
        $this->makeCategoryCard('Z cao', 'Siêu thị', [$this->officialTier(15, 300000)]);

        $this->assertSame(
            ['Z cao', 'A thấp'],
            $this->policyNames($this->categoryLookup('supermarket', $user)),
        );
    }

    #[Test]
    public function category_partitions_official_and_bonus_tiers(): void
    {
        $user = User::factory()->create();

        $this->makeCategoryCard('Thẻ tách loại', 'Siêu thị', [
            $this->bonusTier(50, 300000),
            $this->officialTier(20, 300000),
        ]);

        $cashback = $this->categoryLookup('supermarket', $user)[0]['cashback'];

        $this->assertCount(1, $cashback['official_tiers']);
        $this->assertCount(1, $cashback['bonus_tiers']);
        $this->assertTrue($cashback['bonus_tiers'][0]['is_bonus']);
    }

    #[Test]
    public function category_only_bonus_card_ranks_after_rated_cards(): void
    {
        $user = User::factory()->create();

        $this->makeCategoryCard('Thẻ chính thức', 'Siêu thị', [$this->officialTier(3, 50000)]);
        $this->makeCategoryCard('Thẻ chỉ bonus', 'Siêu thị', [$this->bonusTier(50, 300000)]);

        $matches = $this->categoryLookup('supermarket', $user);

        $this->assertSame(['Thẻ chính thức', 'Thẻ chỉ bonus'], $this->policyNames($matches));
        $this->assertNull($matches[1]['cashback']['highest']);
        $this->assertSame('50.000', $matches[1]['cashback']['highest_bonus']['cashback_percent']);
    }

    #[Test]
    public function category_unclassified_tier_is_treated_as_official(): void
    {
        $user = User::factory()->create();

        $this->makeCategoryCard('Thẻ thường', 'Siêu thị', [['cashback_percent' => '5.000']]);

        $cashback = $this->categoryLookup('supermarket', $user)[0]['cashback'];

        $this->assertSame('5.000', $cashback['highest']['cashback_percent']);
        $this->assertNull($cashback['highest_bonus']);
        $this->assertFalse($cashback['official_tiers'][0]['is_bonus']);
    }

    // =====================================================================
    // Không hồi quy: dữ liệu chính thức thuần giữ nguyên thứ tự cũ
    // =====================================================================

    #[Test]
    public function official_only_cards_keep_rate_then_cap_order(): void
    {
        $user = User::factory()->create();
        $this->seedMcc('5411');

        // Không có bonus ⇒ thứ tự y như trước: tỷ lệ rồi trần giảm dần.
        $this->makeMccCard('Thẻ A', '5411', [$this->officialTier(20, 300000)]);
        $this->makeMccCard('Thẻ B', '5411', [$this->officialTier(20, 200000)]);
        $this->makeMccCard('Thẻ C', '5411', [$this->officialTier(15, 500000)]);

        $this->assertSame(
            ['Thẻ A', 'Thẻ B', 'Thẻ C'],
            $this->policyNames($this->mccLookup('5411', $user)),
        );
    }
}
