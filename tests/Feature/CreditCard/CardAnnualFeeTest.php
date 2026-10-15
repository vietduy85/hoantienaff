<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\UserCard;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Phí thường niên của thẻ (`annual_fee`, `annual_fee_waiver_spend`,
 * `next_annual_fee_date`) — API Thêm/Sửa thẻ + màn "Quản lý thẻ".
 *
 * Ba cột được thêm ở migration
 * `2026_10_14_000012_add_annual_fee_fields_to_credit_card_user_cards_table`
 * (nullable), nên bài test khoá các bất biến:
 *   1. Tạo thẻ với đủ 3 trường ⇒ lưu đúng dữ liệu.
 *   2. Không nhập trường nào ⇒ vẫn tạo được, lưu `NULL` (không phải 0).
 *   3. Chỉ nhập một/hai trường ⇒ vẫn thành công.
 *   4. Sửa từng trường độc lập ⇒ lưu đúng.
 *   5. Xoá giá trị ⇒ lưu thành `NULL` (PATCH gửi `null`).
 *   6. Thẻ cũ (không có 3 trường) vẫn đọc/sửa bình thường, không bị ảnh hưởng.
 *   7. Giá trị sai định dạng/không hợp lệ bị 422.
 *   8. Form Quản lý thẻ có nhóm "Phí thường niên" và nạp đúng dữ liệu khi sửa.
 *
 * PHẠM VI: chỉ LƯU cấu hình. Không có tính toán lũy kế / miễn phí / thông báo.
 */
class CardAnnualFeeTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
    }

    // =====================================================================
    // API: tạo thẻ
    // =====================================================================

    #[Test]
    public function a_card_can_be_created_with_the_full_annual_fee_group(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ có phí thường niên',
            'annual_fee' => 500000,
            'annual_fee_waiver_spend' => 60000000,
            'next_annual_fee_date' => '2026-12-15',
        ]);

        $response->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));

        // `decimal(16,2)` đọc về là chuỗi; ngày là DATE.
        $this->assertSame('500000.00', $card->annual_fee);
        $this->assertSame('60000000.00', $card->annual_fee_waiver_spend);
        $this->assertSame('2026-12-15', $card->next_annual_fee_date->toDateString());

        // API trả kèm đúng dữ liệu để form sửa thẻ nạp lại.
        $response->assertJsonPath('data.annual_fee', 500000);
        $response->assertJsonPath('data.annual_fee_waiver_spend', 60000000);
        $response->assertJsonPath('data.next_annual_fee_date', '2026-12-15');
    }

    #[Test]
    public function a_card_can_be_created_without_any_annual_fee_field(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ không khai phí',
        ]);

        $response->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));

        // Để trống là NULL, KHÔNG phải 0.
        $this->assertNull($card->annual_fee);
        $this->assertNull($card->annual_fee_waiver_spend);
        $this->assertNull($card->next_annual_fee_date);
    }

    #[Test]
    public function an_explicit_null_annual_fee_group_stays_null(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ để trống phí',
            'annual_fee' => null,
            'annual_fee_waiver_spend' => null,
            'next_annual_fee_date' => null,
        ]);

        $response->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));
        $this->assertNull($card->annual_fee);
        $this->assertNull($card->annual_fee_waiver_spend);
        $this->assertNull($card->next_annual_fee_date);
    }

    #[Test]
    public function a_card_can_be_created_with_only_one_annual_fee_field(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ chỉ khai phí',
            'annual_fee' => 300000,
        ]);

        $response->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));
        $this->assertSame('300000.00', $card->annual_fee);
        $this->assertNull($card->annual_fee_waiver_spend);
        $this->assertNull($card->next_annual_fee_date);
    }

    #[Test]
    public function a_card_can_be_created_with_two_annual_fee_fields(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ khai phí và ngày',
            'annual_fee' => 400000,
            'next_annual_fee_date' => '2027-01-02',
        ]);

        $response->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));
        $this->assertSame('400000.00', $card->annual_fee);
        $this->assertNull($card->annual_fee_waiver_spend);
        $this->assertSame('2027-01-02', $card->next_annual_fee_date->toDateString());
    }

    // =====================================================================
    // API: sửa thẻ
    // =====================================================================

    #[Test]
    public function each_annual_fee_field_can_be_updated_independently(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'annual_fee' => 100000,
            'annual_fee_waiver_spend' => 20000000,
            'next_annual_fee_date' => '2026-01-10',
        ]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['annual_fee' => 550000])
            ->assertOk();

        $card->refresh();
        $this->assertSame('550000.00', $card->annual_fee);
        $this->assertSame('20000000.00', $card->annual_fee_waiver_spend);
        $this->assertSame('2026-01-10', $card->next_annual_fee_date->toDateString());

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'annual_fee_waiver_spend' => 75000000,
                'next_annual_fee_date' => '2027-03-05',
            ])
            ->assertOk();

        $card->refresh();
        $this->assertSame('550000.00', $card->annual_fee);
        $this->assertSame('75000000.00', $card->annual_fee_waiver_spend);
        $this->assertSame('2027-03-05', $card->next_annual_fee_date->toDateString());
    }

    #[Test]
    public function clearing_the_annual_fee_fields_stores_null(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'annual_fee' => 100000,
            'annual_fee_waiver_spend' => 20000000,
            'next_annual_fee_date' => '2026-01-10',
        ]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'annual_fee' => null,
                'annual_fee_waiver_spend' => null,
                'next_annual_fee_date' => null,
            ])
            ->assertOk();

        $card->refresh();
        $this->assertNull($card->annual_fee);
        $this->assertNull($card->annual_fee_waiver_spend);
        $this->assertNull($card->next_annual_fee_date);
    }

    #[Test]
    public function a_stranger_cannot_change_somebody_elses_annual_fee(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['annual_fee' => 100000]);

        $this->actingAs(User::factory()->create())
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['annual_fee' => 999999])
            ->assertForbidden();

        $this->assertSame('100000.00', $card->fresh()->annual_fee);
    }

    // =====================================================================
    // Dữ liệu cũ / thao tác khác không đụng tới nhóm phí
    // =====================================================================

    #[Test]
    public function patching_other_fields_leaves_the_annual_fee_group_untouched(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'annual_fee' => 100000,
            'annual_fee_waiver_spend' => 20000000,
            'next_annual_fee_date' => '2026-01-10',
        ]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['name' => 'Đổi tên thẻ'])
            ->assertOk();

        $card->refresh();
        $this->assertSame('100000.00', $card->annual_fee);
        $this->assertSame('20000000.00', $card->annual_fee_waiver_spend);
        $this->assertSame('2026-01-10', $card->next_annual_fee_date->toDateString());
    }

    #[Test]
    public function a_legacy_card_without_annual_fee_data_keeps_working(): void
    {
        // Thẻ tạo trước khi có tính năng: không có 3 cột ⇒ đọc ra NULL, sửa được
        // trường khác mà không phát sinh lỗi.
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ cũ']);

        $this->assertNull($card->annual_fee);
        $this->assertNull($card->annual_fee_waiver_spend);
        $this->assertNull($card->next_annual_fee_date);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['credit_limit' => 30000000])
            ->assertOk();

        $card->refresh();
        $this->assertSame('30000000.00', $card->credit_limit);
        $this->assertNull($card->annual_fee);
        $this->assertNull($card->annual_fee_waiver_spend);
        $this->assertNull($card->next_annual_fee_date);
    }

    // =====================================================================
    // Validate
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function invalidAnnualFeeProvider(): array
    {
        return [
            'phí âm' => ['annual_fee', -1000],
            'chi tiêu âm' => ['annual_fee_waiver_spend', -1],
            'phí không phải số' => ['annual_fee', 'nhiều tiền'],
            'chi tiêu vượt sức chứa cột' => ['annual_fee_waiver_spend', 99999999999999999],
            'ngày sai định dạng' => ['next_annual_fee_date', '15/12/2026'],
            'ngày rác' => ['next_annual_fee_date', 'không phải ngày'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAnnualFeeProvider')]
    public function an_invalid_annual_fee_field_is_rejected_on_create(string $field, mixed $value): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ phí sai',
            $field => $value,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors($field);

        // 422 ⇒ KHÔNG tạo thẻ mồ côi.
        $this->assertSame(0, UserCard::query()->ownedBy($this->owner->id)->count());
    }

    #[Test]
    #[DataProvider('invalidAnnualFeeProvider')]
    public function an_invalid_annual_fee_field_is_rejected_on_update(string $field, mixed $value): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [$field => $value])
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    // =====================================================================
    // Màn "Quản lý thẻ"
    // =====================================================================

    #[Test]
    public function the_manage_form_has_a_dedicated_annual_fee_group(): void
    {
        $html = $this->actingAs($this->owner)->get(route('credit-cards.manage'))->assertOk()->getContent();

        $this->assertStringContainsString('Phí thường niên', $html);

        // Nhãn khớp từng ô.
        $this->assertStringContainsString('for="cc-annual-fee"', $html);
        $this->assertStringContainsString('for="cc-annual-fee-waiver-spend"', $html);
        $this->assertStringContainsString('for="cc-next-annual-fee-date"', $html);

        // Ô tiền: text + inputmode decimal + min 0, nối đúng biến Alpine.
        $this->assertMatchesRegularExpression('/min="0"[^>]*id="cc-annual-fee"/', $html);
        $this->assertMatchesRegularExpression('/min="0"[^>]*id="cc-annual-fee-waiver-spend"/', $html);
        $this->assertStringContainsString('form.annual_fee = ccMoneyParseInput(ccRaw)', $html);
        $this->assertStringContainsString('form.annual_fee_waiver_spend = ccMoneyParseInput(ccRaw)', $html);

        // Ô ngày: date picker gắn Alpine.
        $this->assertStringContainsString('type="date"', $html);
        $this->assertStringContainsString('id="cc-next-annual-fee-date"', $html);
        $this->assertStringContainsString('x-model="form.next_annual_fee_date"', $html);
    }

    #[Test]
    public function the_manage_page_feeds_saved_annual_fee_values_into_the_edit_form(): void
    {
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ nạp phí',
            'annual_fee' => 500000,
            'annual_fee_waiver_spend' => 60000000,
            'next_annual_fee_date' => '2026-12-15',
        ]);

        $html = $this->actingAs($this->owner)->get(route('credit-cards.manage'))->assertOk()->getContent();

        // State Alpine khởi tạo (nguồn để `openEdit` nạp lại form) mang đúng giá trị.
        // `@js()` mã hoá `"` thành `\u0022`, nên kiểm theo GIÁ TRỊ (chuỗi này chỉ
        // xuất hiện trong state, không có ở placeholder của ô nhập).
        $this->assertStringContainsString('annual_fee', $html);
        $this->assertStringContainsString('500000.00', $html);
        $this->assertStringContainsString('60000000.00', $html);
        $this->assertStringContainsString('2026-12-15', $html);
    }

    #[Test]
    public function the_manage_page_submits_the_new_keys_in_the_save_payload(): void
    {
        $html = $this->actingAs($this->owner)->get(route('credit-cards.manage'))->assertOk()->getContent();

        $this->assertStringContainsString('annual_fee: this.form.annual_fee === \'\' ? null : this.form.annual_fee', $html);
        $this->assertStringContainsString('annual_fee_waiver_spend: this.form.annual_fee_waiver_spend === \'\' ? null : this.form.annual_fee_waiver_spend', $html);
        $this->assertStringContainsString('next_annual_fee_date: this.form.next_annual_fee_date || null', $html);
    }
}
