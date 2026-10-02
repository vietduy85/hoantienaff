<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\UserCard;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Hạn mức tín dụng (`credit_limit`) — API + màn "Quản lý thẻ".
 *
 * Cột đã có sẵn ở `2026_10_01_000005_create_credit_card_user_cards_table`
 * (`decimal(16,2) NULL`), nên bài test này KHÔNG thêm migration: nó khoá hành vi
 * của các lớp đã có sẵn (`StoreUserCardRequest`, `UpdateUserCardRequest`,
 * `UserCardService`) và của form Quản lý thẻ vừa thêm ô nhập.
 *
 * Ba bất biến:
 *   1. Hạn mức là TUỲ CHỌN — để trống thì `NULL`, không phải 0 (0 là "không có
 *      hạn mức" khác hẳn "chưa khai").
 *   2. Số âm bị từ chối ở server, không phải chỉ ở `<input min="0">`.
 *   3. Form Quản lý thẻ gửi khoá `credit_limit` và hiển thị lại hạn mức đúng
 *      định dạng VND — cùng bộ định dạng với Tổng quan.
 */
class CardCreditLimitTest extends TestCase
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
    public function a_user_can_create_a_card_with_a_credit_limit(): void
    {
        $bank = $this->makeBank();

        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $bank->id,
            'name' => 'Thẻ MB chính',
            'credit_limit' => 150000000,
        ]);

        $response->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));

        // Cột `decimal(16,2)` nên đọc về là chuỗi "150000000.00", không phải float.
        $this->assertSame('150000000.00', $card->credit_limit);
    }

    #[Test]
    public function a_card_can_be_created_without_a_credit_limit(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ chưa khai hạn mức',
        ]);

        $response->assertCreated();

        $this->assertNull(UserCard::findOrFail($response->json('data.id'))->credit_limit);
    }

    #[Test]
    public function an_explicit_null_credit_limit_stays_null(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ để trống hạn mức',
            'credit_limit' => null,
        ]);

        $response->assertCreated();

        $this->assertNull(UserCard::findOrFail($response->json('data.id'))->credit_limit);
    }

    // =====================================================================
    // API: validate
    // =====================================================================

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidCreditLimitProvider(): array
    {
        return [
            'số âm' => [-1000, 'credit_limit'],
            'chuỗi rác' => ['nhiều tiền', 'credit_limit'],
            'NaN' => ['NaN', 'credit_limit'],
        ];
    }

    #[Test]
    #[DataProvider('invalidCreditLimitProvider')]
    public function an_invalid_credit_limit_is_rejected(mixed $value, string $field): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ hạn mức sai',
            'credit_limit' => $value,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors($field);

        // 422 ⇒ KHÔNG được tạo thẻ mồ côi.
        $this->assertSame(0, UserCard::query()->ownedBy($this->owner->id)->count());
    }

    #[Test]
    public function a_credit_limit_above_the_column_capacity_is_rejected(): void
    {
        // `decimal(16,2)` ⇒ tối đa 9.999.999.999.999,99. Số vượt ngưỡng phải bị
        // chặn ở tầng validation, không được đẩy xuống DB rồi nhận lỗi SQL.
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ hạn mức khổng lồ',
            'credit_limit' => 99999999999999999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('credit_limit');

        $this->assertSame(0, UserCard::query()->ownedBy($this->owner->id)->count());
    }

    #[Test]
    public function a_zero_credit_limit_is_allowed_because_zero_is_a_real_answer(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ không có hạn',
            'credit_limit' => 0,
        ]);

        $response->assertCreated();

        $this->assertSame('0.00', UserCard::findOrFail($response->json('data.id'))->credit_limit);
    }

    // =====================================================================
    // API: sửa thẻ
    // =====================================================================

    #[Test]
    public function a_credit_limit_can_be_updated(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['credit_limit' => 20000000]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'credit_limit' => 90000000,
            ])
            ->assertOk();

        $this->assertSame('90000000.00', $card->fresh()->credit_limit);
    }

    #[Test]
    public function clearing_the_credit_limit_stores_null(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['credit_limit' => 20000000]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'credit_limit' => null,
            ])
            ->assertOk();

        $this->assertNull($card->fresh()->credit_limit);
    }

    #[Test]
    public function patching_other_fields_leaves_the_credit_limit_untouched(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['credit_limit' => 20000000]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['name' => 'Thẻ đã đổi tên'])
            ->assertOk();

        $this->assertSame('20000000.00', $card->fresh()->credit_limit);
    }

    #[Test]
    public function a_negative_credit_limit_is_rejected_when_updating(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['credit_limit' => 20000000]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['credit_limit' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('credit_limit');

        $this->assertSame('20000000.00', $card->fresh()->credit_limit);
    }

    #[Test]
    public function a_stranger_cannot_change_somebody_elses_credit_limit(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['credit_limit' => 20000000]);

        $this->actingAs(User::factory()->create())
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['credit_limit' => 1])
            ->assertForbidden();

        $this->assertSame('20000000.00', $card->fresh()->credit_limit);
    }

    // =====================================================================
    // Màn "Quản lý thẻ"
    // =====================================================================

    #[Test]
    public function the_manage_form_has_a_credit_limit_field(): void
    {
        $html = $this->actingAs($this->owner)->get(route('credit-cards.manage'))->assertOk()->getContent();

        // Ô nhập hạn mức: type number + min 0 (chặn âm ngay ở trình duyệt) và
        // được Alpine nối vào form.
        $this->assertStringContainsString('id="cc-credit-limit"', $html);
        $this->assertMatchesRegularExpression(
            '/id="cc-credit-limit"[^>]*type="number"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="cc-credit-limit"[^>]*min="0"/',
            $html
        );
        $this->assertStringContainsString('x-model="form.credit_limit"', $html);
    }

    #[Test]
    public function the_manage_page_labels_the_credit_limit_field(): void
    {
        $html = $this->actingAs($this->owner)->get(route('credit-cards.manage'))->assertOk()->getContent();

        $this->assertStringContainsString('Hạn mức tín dụng', $html);
        $this->assertStringContainsString('for="cc-credit-limit"', $html);
    }

    #[Test]
    public function the_card_list_shows_the_credit_limit_with_vnd_formatting(): void
    {
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ VCB',
            'credit_limit' => 150000000,
        ]);

        $html = $this->actingAs($this->owner)->get(route('credit-cards.manage'))->assertOk()->getContent();

        $this->assertStringContainsString('150.000.000', $html);
    }

    #[Test]
    public function a_card_without_a_credit_limit_says_so_instead_of_showing_zero(): void
    {
        $this->makeUserCard($this->owner->id, [
            'name' => 'Thẻ chưa khai',
            'credit_limit' => null,
        ]);

        $html = $this->actingAs($this->owner)->get(route('credit-cards.manage'))->assertOk()->getContent();

        $this->assertStringContainsString('Chưa khai', $html);
    }

    #[Test]
    public function the_credit_limit_of_another_user_never_reaches_the_manage_page(): void
    {
        $this->makeUserCard($this->owner->id, ['credit_limit' => 50000000]);
        $this->makeUserCard(User::factory()->create()->id, ['credit_limit' => 750000000]);

        $html = $this->actingAs($this->owner)->get(route('credit-cards.manage'))->assertOk()->getContent();

        $this->assertStringContainsString('50.000.000', $html);
        $this->assertStringNotContainsString('750.000.000', $html);
    }
}
