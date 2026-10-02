<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CategoryCombo;
use App\Models\CreditCard\Policy;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\CreditCard\PolicyTier;
use App\Models\CreditCard\PolicyTierCategory;
use App\Models\CreditCard\PolicyVersion;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\UserCardService;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Màn "Quản lý thẻ" (/thetindung/quan-ly-the) — CRUD thẻ, kỳ sao kê tự tính,
 * và chọn chính sách hoàn tiền bằng cơ chế SAO CHÉP.
 *
 * Bốn bất biến được khóa ở đây:
 *   1. CRUD thẻ chạy qua đúng API + policy sở hữu (thẻ người khác ⇒ 403).
 *   2. Kỳ sao kê: client KHÔNG gửi ngày kết thúc, server tự tính từ ngày bắt đầu.
 *   3. Chọn chính sách = CLONE, mẫu gốc (hệ thống lẫn của user) giữ nguyên.
 *   4. Mẫu của user khác không bao giờ lọt vào danh sách chọn.
 */
class CardManagementUxTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    // =====================================================================
    // CRUD thẻ
    // =====================================================================

    #[Test]
    public function a_user_can_create_a_card_with_the_full_profile(): void
    {
        $bank = $this->makeBank();

        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $bank->id,
            'name' => 'Thẻ MB chính',
            'statement_period_start' => '2026-10-01',
            'payment_due_day' => 25,
            'spending_deadline_day' => 20,
            'desired_spend' => 20000000,
            'promotion_info' => 'Hoàn 2% mọi giao dịch quán cà phê tháng này.',
            'note' => 'Thẻ chính, dùng hằng ngày.',
        ]);

        $response->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));

        $this->assertSame('Thẻ MB chính', $card->name);
        $this->assertSame($bank->id, (int) $card->bank_id);
        $this->assertSame(25, (int) $card->payment_due_day);
        $this->assertSame(20, (int) $card->spending_deadline_day);
        $this->assertSame('20000000.00', $card->desired_spend);
        $this->assertSame('Hoàn 2% mọi giao dịch quán cà phê tháng này.', $card->promotion_info);
        $this->assertSame('Thẻ chính, dùng hằng ngày.', $card->note);

        // Owner lấy từ `auth()`, KHÔNG từ request.
        $this->assertSame((int) $this->owner->id, (int) $card->user_id);
    }

    #[Test]
    public function a_user_can_update_the_profile_of_their_own_card(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Tên cũ']);

        $response = $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cards.update', $card->id),
            [
                'name' => 'Tên mới',
                'statement_period_start' => '2026-11-15',
                'desired_spend' => 30000000,
                'promotion_info' => 'Ưu đãi mới',
            ]
        );

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Tên mới');

        $card->refresh();

        $this->assertSame('Tên mới', $card->name);
        $this->assertSame('30000000.00', $card->desired_spend);
        $this->assertSame('Ưu đãi mới', $card->promotion_info);
    }

    #[Test]
    public function a_negative_desired_spend_is_rejected(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'name' => 'Thẻ âm',
            'desired_spend' => -1,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('desired_spend');
    }

    #[Test]
    public function a_user_can_clear_the_desired_spend_later(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['desired_spend' => 30000000]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'desired_spend' => null,
            ])
            ->assertOk();

        $this->assertNull($card->refresh()->desired_spend, 'Xoá số tiền mong muốn phải lưu NULL, không phải 0 hay chuỗi rỗng.');
    }

    #[Test]
    public function a_user_cannot_read_or_edit_another_users_card(): void
    {
        $theirs = $this->makeUserCard($this->stranger->id);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $theirs->id), ['name' => 'Cướp'])
            ->assertForbidden();

        $this->assertNotSame('Cướp', $theirs->refresh()->name);
    }

    #[Test]
    public function the_manage_page_only_lists_the_owners_own_cards(): void
    {
        $mine = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ của tôi A']);
        $theirs = $this->makeUserCard($this->stranger->id, ['name' => 'Thẻ của họ B']);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($mine->name, $html);
        $this->assertStringNotContainsString($theirs->name, $html);
    }

    #[Test]
    public function a_user_can_add_a_card_before_knowing_the_issuing_bank(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'name' => 'Thẻ mới nhận, chưa biết ngân hàng',
            'payment_due_day' => 25,
        ]);

        $response->assertCreated();

        $card = UserCard::findOrFail($response->json('data.id'));

        $this->assertNull($card->bank_id);
        $this->assertNull($response->json('data.bank'), 'Presenter phải trả bank = null, không phải bỏ field.');
    }

    #[Test]
    public function a_user_can_clear_the_bank_of_a_card_later(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['bank_id' => null])
            ->assertOk()
            ->assertJsonPath('data.bank', null);

        $this->assertNull($card->refresh()->bank_id);
    }

    #[Test]
    public function omitting_the_bank_field_keeps_the_current_one(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $bankId = (int) $card->bank_id;

        // PATCH không nhắc tới `bank_id` ⇒ không được đụng (khác hẳn gửi `null`).
        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), ['name' => 'Chỉ đổi tên'])
            ->assertOk();

        $this->assertSame($bankId, (int) $card->refresh()->bank_id);
    }

    #[Test]
    public function an_unknown_bank_is_still_rejected(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.cards.store'), ['name' => 'Thẻ', 'bank_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_id');
    }

    #[Test]
    public function a_hidden_bank_cannot_be_newly_assigned(): void
    {
        $bank = $this->makeBank(['is_active' => false]);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.cards.store'), ['name' => 'Thẻ', 'bank_id' => $bank->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_id');
    }

    // =====================================================================
    // Kỳ sao kê: chọn ngày bắt đầu ⇒ tự tính ngày kết thúc
    // =====================================================================

    /**
     * Công thức: end = start + 1 tháng − 1 ngày.
     */
    #[Test]
    #[DataProvider('statementPeriodCases')]
    public function the_statement_period_end_is_derived_from_the_start_date(string $start, string $expectedEnd): void
    {
        $service = app(UserCardService::class);

        $this->assertSame($expectedEnd, $service->statementPeriodEnd($start));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statementPeriodCases(): array
    {
        return [
            // Đầu tháng ⇒ hết tháng.
            'đầu tháng có 31 ngày' => ['2026-10-01', '2026-10-31'],
            'đầu tháng có 30 ngày' => ['2026-11-01', '2026-11-30'],
            'tháng 2 nhuận' => ['2028-02-01', '2028-02-29'],
            'tháng 2 không nhuận' => ['2026-02-01', '2026-02-28'],

            // Giữa tháng ⇒ cộng đúng một tháng.
            'giữa tháng' => ['2026-10-15', '2026-11-14'],
            'ngày 5' => ['2026-03-05', '2026-04-04'],

            // Ngày cuối tháng gặp tháng ngắn hơn: kỳ phủ HẾT tháng sau.
            'ngày 31 sang tháng ngắn' => ['2026-01-31', '2026-02-27'],
            'ngày 30 sang tháng ngắn' => ['2026-01-30', '2026-02-27'],
        ];
    }

    #[Test]
    public function creating_a_card_computes_and_persists_the_period_end(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ có kỳ sao kê',
            'statement_period_start' => '2026-10-15',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.statement_period_start', '2026-10-15');
        $response->assertJsonPath('data.statement_period_end', '2026-11-14');

        $this->assertSame('2026-11-14', UserCard::findOrFail($response->json('data.id'))->statement_period_end->toDateString());
    }

    #[Test]
    public function the_client_cannot_send_its_own_period_end(): void
    {
        $response = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'bank_id' => $this->makeBank()->id,
            'name' => 'Thẻ cố gửi end',
            'statement_period_start' => '2026-10-01',
            // Cố ý gửi ngày kết thúc sai: field này không nằm trong rules() nên bị
            // bỏ qua, server vẫn tự tính ⇒ không bao giờ có cặp ngày lệch nhau.
            'statement_period_end' => '2026-12-31',
        ]);

        $response->assertCreated();
        $this->assertSame('2026-10-31', $response->json('data.statement_period_end'));
    }

    #[Test]
    public function changing_the_start_date_recomputes_the_period_end(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_period_start' => '2026-10-01',
            'statement_period_end' => '2026-10-31',
        ]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'statement_period_start' => '2026-10-20',
            ])
            ->assertOk()
            ->assertJsonPath('data.statement_period_end', '2026-11-19');
    }

    #[Test]
    public function clearing_the_start_date_clears_the_period_end(): void
    {
        $card = $this->makeUserCard($this->owner->id, [
            'statement_period_start' => '2026-10-01',
            'statement_period_end' => '2026-10-31',
        ]);

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.cards.update', $card->id), [
                'statement_period_start' => null,
            ])
            ->assertOk();

        $card->refresh();

        $this->assertNull($card->statement_period_start);
        $this->assertNull($card->statement_period_end);
    }

    // =====================================================================
    // Chính sách hoàn tiền: chọn mẫu ⇒ sao chép thành bản riêng của thẻ
    // =====================================================================

    #[Test]
    public function picking_a_system_policy_clones_it_instead_of_sharing_it(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $card = $this->makeUserCard($this->owner->id);

        $blueprintId = $template->default_version_id;
        $tierId = PolicyTier::query()->where('policy_id', $blueprintId)->value('id');
        $ruleId = PolicyTierCategory::query()->where('tier_id', $tierId)->value('id');
        $categoryId = PolicyTierCategory::query()->whereKey($ruleId)->value('category_id');

        $response = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.policies.store', $card->id),
            ['mode' => 'clone_system', 'template_id' => $template->id, 'effective_from' => '2026-10-01']
        );

        $response->assertCreated();

        $clonedId = $response->json('data.id');
        $clonedTierId = PolicyTier::query()->where('policy_id', $clonedId)->value('id');

        // MỌI identity đều mới — thẻ không tham chiếu bản ghi của mẫu.
        $this->assertNotSame($blueprintId, $clonedId);
        $this->assertNotSame($tierId, $clonedTierId);
        $this->assertSame(PolicyTemplate::SCOPE_SYSTEM, $template->refresh()->scope);

        // Danh mục được dùng chung (identity bất biến) nhưng combo phải snapshot.
        $clonedRule = PolicyTierCategory::query()->where('tier_id', $clonedTierId)->first();
        $this->assertNotSame($ruleId, (int) $clonedRule->id);
        $this->assertSame($categoryId, $clonedRule->category_id);

        // Thẻ trỏ tới bản clone, không trỏ mẫu.
        $this->assertSame($clonedId, (int) $card->refresh()->current_policy_id);
    }

    #[Test]
    public function editing_a_cloned_policy_does_not_touch_the_source_template(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.policies.store', $card->id),
            ['mode' => 'clone_system', 'template_id' => $template->id, 'effective_from' => '2026-10-01']
        )->assertCreated();

        $clonedId = (int) $card->refresh()->current_policy_id;
        $clonedTierId = (int) PolicyTier::query()->where('policy_id', $clonedId)->value('id');
        $clonedRuleId = (int) PolicyTierCategory::query()->where('tier_id', $clonedTierId)->value('id');
        $blueprintRuleId = (int) PolicyTierCategory::query()
            ->where('tier_id', PolicyTier::query()->where('policy_id', $template->default_version_id)->value('id'))
            ->value('id');

        // Chụp lại trạng thái mẫu TRƯỚC khi sửa để so sánh được.
        $blueprintPercentBefore = PolicyTierCategory::findOrFail($blueprintRuleId)->cashback_percent;

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.rules.update', $clonedRuleId), ['cashback_percent' => 9.5])
            ->assertOk();

        // Bản clone đổi... (cột lưu scale 3 nên 9.5 thành '9.500')
        $this->assertSame('9.500', PolicyTierCategory::findOrFail($clonedRuleId)->cashback_percent);

        // ...mẫu gốc giữ nguyên.
        $this->assertSame(
            $blueprintPercentBefore,
            PolicyTierCategory::findOrFail($blueprintRuleId)->cashback_percent,
            'Sửa bản clone đã làm thay đổi mẫu hệ thống gốc.'
        );
    }

    #[Test]
    public function a_user_can_reuse_their_own_saved_policy_template(): void
    {
        $source = $this->makeUserCard($this->owner->id);
        $category = $this->makeSystemCategory();

        $this->makePolicyForCard($source, [['min' => 0, 'max' => null]], [
            ['category_id' => $category->id, 'percent' => 3],
        ]);

        // Lưu thành mẫu riêng rồi dùng lại cho thẻ thứ hai.
        $templateId = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.policies.templates.store', $source->id),
            ['name' => 'Mẫu ưu đãi riêng']
        )->assertCreated()->json('data.id');

        $target = $this->makeUserCard($this->owner->id);

        $response = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.policies.store', $target->id),
            ['mode' => 'clone_user', 'template_id' => $templateId, 'effective_from' => '2026-10-01']
        );

        $response->assertCreated();

        $this->assertSame(Policy::class, Policy::findOrFail($response->json('data.id'))::class);
        $this->assertSame((int) $response->json('data.id'), (int) $target->refresh()->current_policy_id);
        $this->assertNotSame((int) $source->current_policy_id, (int) $target->current_policy_id);
    }

    #[Test]
    public function the_template_picker_never_offers_another_users_template(): void
    {
        $mine = $this->makeUserTemplateWithBlueprint($this->owner->id, 'Mẫu của tôi');
        $theirs = $this->makeUserTemplateWithBlueprint($this->stranger->id, 'Mẫu của người khác');
        $system = $this->makeSystemTemplateWithBlueprint();

        $ids = collect($this->actingAs($this->owner)->getJson(route('credit-cards.api.templates.index'))->assertOk()->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $this->assertTrue($ids->contains((int) $mine->id), 'Mẫu của chính user phải xuất hiện.');
        $this->assertTrue($ids->contains((int) $system->id), 'Mẫu hệ thống phải xuất hiện.');
        $this->assertFalse($ids->contains((int) $theirs->id), 'Mẫu của user khác KHÔNG được xuất hiện.');
    }

    #[Test]
    public function a_user_cannot_attach_another_users_template_to_their_card(): void
    {
        $theirs = $this->makeUserTemplateWithBlueprint($this->stranger->id, 'Mẫu của người khác');
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.policies.store', $card->id), [
                'mode' => 'clone_user',
                'template_id' => $theirs->id,
                'effective_from' => '2026-10-01',
            ])
            ->assertStatus(422);

        $this->assertNull($card->refresh()->current_policy_id);
    }

    #[Test]
    public function the_rule_presenter_exposes_the_three_target_types(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $card = $this->makeUserCard($this->owner->id);

        $clonedId = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.policies.store', $card->id),
            ['mode' => 'clone_system', 'template_id' => $template->id, 'effective_from' => '2026-10-01']
        )->assertCreated()->json('data.id');

        $tierId = (int) PolicyTier::query()->where('policy_id', $clonedId)->value('id');

        $this->actingAs($this->owner)->postJson(route('credit-cards.api.rules.store', $tierId), [
            'scope_type' => 'other',
            'cashback_percent' => 1,
        ])->assertCreated();

        $rules = collect($this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.policies.show', ['userCard' => $card->id, 'policy' => $clonedId]))
            ->assertOk()
            ->json('data.tiers.0.rules'));

        // Danh mục cụ thể + fallback = 2 trạng thái, đủ để UI dựng nhãn.
        $this->assertTrue($rules->contains(fn ($rule) => $rule['target_type'] === 'category'));
        $this->assertTrue($rules->contains(fn ($rule) => $rule['target_type'] === 'other'));

        // Mỗi rule phải có khoá combo để UI không phải suy từ category_id.
        $this->assertTrue($rules->every(fn ($rule) => array_key_exists('combo_id', $rule) && array_key_exists('combo_name', $rule)));
    }

    #[Test]
    public function picking_another_policy_replaces_the_current_one(): void
    {
        $first = $this->makeSystemTemplateWithBlueprint();
        $second = $this->makeSystemTemplateWithBlueprint();
        $card = $this->makeUserCard($this->owner->id);

        $firstId = (int) $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.policies.store', $card->id),
            ['mode' => 'clone_system', 'template_id' => $first->id, 'effective_from' => '2026-10-01']
        )->assertCreated()->json('data.id');

        // Chọn lại: thẻ phải chuyển sang bản clone thứ hai.
        $secondId = (int) $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.policies.store', $card->id),
            ['mode' => 'clone_system', 'template_id' => $second->id, 'effective_from' => '2026-11-01']
        )->assertCreated()->json('data.id');

        $card->refresh();
        $this->assertSame($secondId, (int) $card->current_policy_id);

        // Danh sách version theo thẻ chỉ lấy CHUỖI của policy hiện tại ⇒ bản cũ
        // không còn lọt lên UI (nhưng dữ liệu cũ vẫn còn để đối soát kỳ đã chốt).
        $listed = collect($this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.policies.index', $card->id))
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $this->assertTrue($listed->contains($secondId));
        $this->assertFalse($listed->contains($firstId));
        $this->assertNotNull(Policy::find($firstId), 'Bản ghi policy cũ phải được giữ lại, không xoá cứng.');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    #[Test]
    public function saving_a_card_and_its_policy_happens_in_one_request(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();

        $response = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ kèm chính sách',
                'policy' => [
                    'template_id' => $template->id,
                    'effective_from' => '2026-10-01',
                ],
            ]
        );

        $response->assertCreated();

        $cardId = (int) $response->json('data.id');
        $policyId = (int) $response->json('data.policy.id');

        $this->assertNotNull($policyId, 'Lưu thẻ phải kèm bản policy clone trong cùng response.');
        $this->assertSame($policyId, (int) UserCard::findOrFail($cardId)->current_policy_id);
        $this->assertSame(
            1,
            PolicyTier::query()->where('policy_id', $policyId)->count(),
            'Bản clone phải mang theo bậc của mẫu.'
        );

        // Trần hoàn của bậc là trường summary cần — phải lộ ra API.
        $this->assertArrayHasKey(
            'max_cashback_per_period',
            $this->actingAs($this->owner)
                ->getJson(route('credit-cards.api.policies.show', [$cardId, $policyId]))
                ->assertOk()
                ->json('data.tiers.0')
        );
    }

    #[Test]
    public function tier_edits_made_before_saving_land_in_a_single_version(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $category = $this->makeSystemCategory();

        $response = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ sửa cấu hình ngay',
                'policy' => [
                    'template_id' => $template->id,
                    'effective_from' => '2026-10-01',
                    'tiers' => [[
                        'name' => 'Bậc đã chỉnh',
                        'min_total_spend' => 0,
                        'max_total_spend' => 5000000,
                        'max_cashback_per_period' => 750000,
                        'rules' => [[
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'category_id' => $category->id,
                            'cashback_percent' => 4.25,
                        ], [
                            'scope_type' => PolicyTierCategory::SCOPE_OTHER,
                            'cashback_percent' => 1,
                        ]],
                    ]],
                ],
            ]
        );

        $response->assertCreated();

        $policyId = (int) $response->json('data.policy.id');

        // Một lần bấm "Lưu thẻ" ⇒ KHÔNG sinh version 2 rỗng.
        $this->assertSame(1, (int) Policy::findOrFail($policyId)->version_no);
        $this->assertSame(
            1,
            Policy::query()->where('user_card_id', (int) $response->json('data.id'))->count(),
            'Chỉ được tồn tại đúng một version cho thẻ vừa tạo.'
        );

        $tier = PolicyTier::query()->where('policy_id', $policyId)->sole();
        $this->assertSame('Bậc đã chỉnh', $tier->name);
        $this->assertSame('5000000.00', $tier->max_total_spend);
        $this->assertSame('750000.00', $tier->max_cashback_per_period);

        $rules = PolicyTierCategory::query()->where('tier_id', $tier->id)->get();

        // Bất biến target: đúng 1 rule danh mục + đúng 1 fallback.
        $this->assertCount(2, $rules);
        $this->assertSame(
            1,
            $rules->filter(fn ($rule) => $rule->isFallback())->count(),
            'Mỗi bậc phải có đúng một quy tắc mặc định.'
        );

        $categoryRule = $rules->firstWhere('category_id', $category->id);
        $this->assertNotNull($categoryRule);
        $this->assertNull($categoryRule->combo_id, 'Rule danh mục không được mang combo_id.');
        $this->assertSame('4.250', $categoryRule->cashback_percent);

        $fallback = $rules->first(fn ($rule) => $rule->isFallback());
        $this->assertNull($fallback->category_id);
        $this->assertNull($fallback->combo_id);
    }

    #[Test]
    public function a_reopened_policy_can_be_saved_back_without_losing_anything(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $category = $this->makeSystemCategory();

        $cardId = (int) $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ giữ nguyên cấu hình',
                'policy' => [
                    'template_id' => $template->id,
                    'effective_from' => '2026-10-01',
                    'tiers' => [[
                        'name' => 'Bậc có cap theo giá trị giao dịch',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'max_cashback_per_period' => 900000,
                        'transaction_caps' => [[
                            'min_transaction_amount' => 1000000,
                            'max_transaction_amount' => 5000000,
                            'max_cashback_per_transaction' => 250000,
                        ]],
                        'rules' => [[
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'category_id' => $category->id,
                            'cashback_percent' => 3,
                            // Ba field dưới KHÔNG có ô nhập trong Policy Editor:
                            // phải đi qua vòng mở form rồi lưu mà không bị rơi.
                            'spend_from' => 200000,
                            'spend_to' => 4000000,
                            'is_enabled' => false,
                            'note' => 'Chỉ áp dụng cho giao dịch tháng 12',
                        ]],
                    ]],
                ],
            ]
        )->assertCreated()->json('data.id');

        $versionId = (int) $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.policies.index', $cardId))
            ->assertOk()
            ->json('data.0.id');

        // Mở lại form: server phải trả đủ phần mà UI không có ô nhập, nếu không vòng
        // "mở rồi lưu" là mất sạch.
        $detail = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.policies.show', ['userCard' => $cardId, 'policy' => $versionId]))
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty(
            $detail['tiers'][0]['transaction_caps'],
            'API không trả `transaction_caps` ⇒ mở form rồi lưu là mất cấu hình cap của bậc.'
        );
        $this->assertSame('Chỉ áp dụng cho giao dịch tháng 12', $detail['tiers'][0]['rules'][0]['note']);

        // Gửi lại y nguyên phần server vừa trả — đúng như trình duyệt làm khi bấm
        // "Lưu thẻ" sau khi không sửa gì.
        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cards.update', $cardId),
            [
                'name' => 'Thẻ giữ nguyên cấu hình',
                'policy' => [
                    'name' => $detail['name'],
                    'effective_from' => '2026-11-01',
                    'tiers' => $detail['tiers'],
                ],
            ]
        )->assertOk();

        $newVersionId = (int) UserCard::findOrFail($cardId)->current_policy_id;
        $tier = PolicyTier::query()->where('policy_id', $newVersionId)->sole();

        $cap = $tier->transactionCaps()->sole();
        $this->assertSame('1000000.00', $cap->min_transaction_amount);
        $this->assertSame('5000000.00', $cap->max_transaction_amount);
        $this->assertSame('250000.00', $cap->max_cashback_per_transaction);
        $this->assertSame('900000.00', $tier->max_cashback_per_period);

        $rule = PolicyTierCategory::query()->where('tier_id', $tier->id)->where('category_id', $category->id)->sole();
        $this->assertSame('200000.00', $rule->spend_from);
        $this->assertSame('4000000.00', $rule->spend_to);
        $this->assertFalse((bool) $rule->is_enabled, 'Rule đang tắt phải giữ nguyên sau khi mở rồi lưu.');
        $this->assertSame('Chỉ áp dụng cho giao dịch tháng 12', $rule->note);
    }

    #[Test]
    public function a_failing_policy_rolls_the_card_back(): void
    {
        $before = UserCard::query()->where('user_id', $this->owner->id)->count();

        // Template tồn tại nhưng KHÔNG có blueprint ⇒ `cloneTemplate()` ném lỗi
        // sau khi thẻ đã được insert.
        $broken = $this->makeSystemTemplate(['name' => 'Mẫu chưa có cấu hình '.uniqid()]);

        $response = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ sẽ bị rollback',
                'policy' => ['template_id' => $broken->id, 'effective_from' => '2026-10-01'],
            ]
        );

        $this->assertTrue(
            $response->status() >= 400,
            "Lỗi policy phải trả lỗi HTTP, nhận được {$response->status()}."
        );

        $this->assertSame(
            $before,
            UserCard::query()->where('user_id', $this->owner->id)->count(),
            'Policy lỗi mà thẻ vẫn còn trong DB ⇒ không rollback toàn bộ transaction.'
        );
        $this->assertSame(
            0,
            UserCard::query()->where('name', 'Thẻ sẽ bị rollback')->count(),
            'Policy lỗi mà bản ghi thẻ vẫn còn trong DB ⇒ không rollback toàn bộ transaction.'
        );
    }

    #[Test]
    public function editing_a_card_edits_its_own_policy_without_touching_other_cards(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $category = $this->makeSystemCategory();

        $first = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'name' => 'Thẻ A',
            'policy' => ['template_id' => $template->id, 'effective_from' => '2026-10-01'],
        ])->assertCreated();

        $second = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'name' => 'Thẻ B',
            'policy' => ['template_id' => $template->id, 'effective_from' => '2026-10-01'],
        ])->assertCreated();

        $cardA = (int) $first->json('data.id');
        $policyA = (int) $first->json('data.policy.id');
        $policyB = (int) $second->json('data.policy.id');

        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cards.update', $cardA),
            [
                'name' => 'Thẻ A đã đổi tên',
                'policy' => [
                    'effective_from' => '2026-11-01',
                    'tiers' => [[
                        'name' => 'Bậc riêng của A',
                        'min_total_spend' => 0,
                        'max_total_spend' => null,
                        'rules' => [[
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'category_id' => $category->id,
                            'cashback_percent' => 7.5,
                        ]],
                    ]],
                ],
            ]
        )->assertOk();

        // Sửa policy RIÊNG của thẻ ⇒ ghi đè version đang chạy, KHÔNG sinh version mới:
        // đây là lần thứ hai bấm "Lưu thẻ" nhưng thẻ vẫn chỉ có MỘT version.
        $this->assertSame(
            $policyA,
            (int) UserCard::findOrFail($cardA)->current_policy_id,
            'Sửa cấu hình phải cập nhật policy đang chạy, không tạo version mới.'
        );

        $this->assertSame(
            1,
            Policy::query()->where('user_card_id', $cardA)->count(),
            'Sửa thẻ không được nhân bản version của thẻ đó.'
        );

        $currentVersion = Policy::findOrFail($policyA);
        $this->assertSame(1, (int) $currentVersion->version_no);
        $this->assertSame(Policy::STATUS_ACTIVE, $currentVersion->status);
        $this->assertNull(
            $currentVersion->effective_to,
            'Version đang chạy không được bị đóng effective_to.'
        );
        $this->assertSame(
            '2026-10-01',
            CarbonImmutable::parse($currentVersion->effective_from)->toDateString(),
            'Ngày hiệu lực của version đã chạy phải giữ nguyên.'
        );

        $newTier = PolicyTier::query()->where('policy_id', $policyA)->sole();
        $this->assertSame('Bậc riêng của A', $newTier->name);
        $this->assertSame(
            7.5,
            (float) PolicyTierCategory::query()->where('tier_id', $newTier->id)->where('category_id', $category->id)->sole()->cashback_percent
        );

        // Thẻ B không bị đụng tới.
        $this->assertSame(
            $policyB,
            (int) UserCard::findOrFail((int) $second->json('data.id'))->current_policy_id
        );
        $this->assertSame(
            1,
            (int) Policy::findOrFail($policyB)->version_no,
            'Sửa policy thẻ A đã làm thẻ B sinh version mới.'
        );
        $this->assertSame(
            'Bậc cơ bản',
            PolicyTier::query()->where('policy_id', $policyB)->sole()->name,
            'Sửa policy thẻ A đã đổi cấu hình của thẻ B.'
        );
    }

    #[Test]
    public function editing_a_card_without_policy_payload_leaves_the_policy_untouched(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();

        $created = $this->actingAs($this->owner)->postJson(route('credit-cards.api.cards.store'), [
            'name' => 'Thẻ giữ nguyên policy',
            'policy' => ['template_id' => $template->id, 'effective_from' => '2026-10-01'],
        ])->assertCreated();

        $cardId = (int) $created->json('data.id');
        $policyId = (int) $created->json('data.policy.id');

        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cards.update', $cardId),
            ['name' => 'Chỉ đổi tên thẻ']
        )->assertOk();

        $this->assertSame($policyId, (int) UserCard::findOrFail($cardId)->current_policy_id);
        $this->assertSame(1, Policy::query()->where('user_card_id', $cardId)->count());
    }

    #[Test]
    public function editing_a_card_never_touches_the_source_policy_of_the_card(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $category = $this->makeSystemCategory();
        $blueprintId = (int) $template->defaultBlueprint()->id;

        $cardId = (int) $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ clone',
                'policy' => ['template_id' => $template->id, 'effective_from' => '2026-10-01'],
            ]
        )->assertCreated()->json('data.id');

        // Chụp lại toàn bộ bản ghi nguồn trước khi sửa thẻ.
        $sourceBefore = Policy::findOrFail($blueprintId)->toArray();
        $sourceTiersBefore = PolicyTier::query()->where('policy_id', $blueprintId)->get()
            ->map(fn (PolicyTier $tier): array => $tier->only(['name', 'sort_order', 'min_total_spend', 'max_cashback_per_period']))->all();

        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cards.update', $cardId),
            [
                'name' => 'Thẻ clone đã sửa',
                'policy' => ['tiers' => [[
                    'name' => 'Bậc sửa tại chỗ',
                    'min_total_spend' => 0,
                    'max_total_spend' => 9000000,
                    'max_cashback_per_period' => 1200000,
                    'transaction_caps' => [[
                        'min_transaction_amount' => 500000,
                        'max_transaction_amount' => 3000000,
                        'max_cashback_per_transaction' => 120000,
                    ]],
                    'rules' => [[
                        'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                        'category_id' => $category->id,
                        'cashback_percent' => 9,
                        'is_enabled' => false,
                        'note' => 'tắt tạm',
                    ]],
                ]]],
            ]
        )->assertOk();

        // Bản ghi nguồn (blueprint của System Policy) y hệt trước khi sửa.
        $this->assertSame(
            $sourceBefore,
            Policy::findOrFail($blueprintId)->toArray(),
            'Sửa policy riêng của thẻ đã làm thay đổi System Policy nguồn.'
        );

        $this->assertSame(
            $sourceTiersBefore,
            PolicyTier::query()->where('policy_id', $blueprintId)->get()
                ->map(fn (PolicyTier $tier): array => $tier->only(['name', 'sort_order', 'min_total_spend', 'max_cashback_per_period']))->all(),
            'Sửa policy riêng của thẻ đã ghi đè bậc của bản ghi nguồn.'
        );

        // Lịch sử version của System Policy vẫn chỉ có blueprint (bản `user_card_id`
        // null). Bản riêng của thẻ không tính vào đó.
        $this->assertSame(
            1,
            PolicyVersion::query()
                ->where('template_id', $template->id)
                ->whereNull('user_card_id')
                ->count(),
            'Lịch sử version của System Policy đã bị đổi.'
        );

        // Còn bản riêng của thẻ thì đã nhận cấu hình mới.
        // Bản riêng của thẻ là bản CLONE, không phải bản ghi của blueprint.
        $cardPolicyId = (int) UserCard::findOrFail($cardId)->current_policy_id;
        $this->assertNotSame(
            $blueprintId,
            $cardPolicyId,
            'Thẻ phải giữ bản riêng (clone), không được tham chiếu thẳng bản ghi nguồn.'
        );

        $tier = PolicyTier::query()->where('policy_id', $cardPolicyId)->sole();
        $this->assertSame('Bậc sửa tại chỗ', $tier->name);
        $this->assertSame('9000000.00', $tier->max_total_spend);
        $this->assertSame('1200000.00', $tier->max_cashback_per_period);

        // Field không có ô nhập vẫn phải được ghi khi sửa tại chỗ.
        $rule = PolicyTierCategory::query()->where('tier_id', $tier->id)->where('category_id', $category->id)->sole();
        $this->assertFalse((bool) $rule->is_enabled);
        $this->assertSame('tắt tạm', $rule->note);

        $cap = $tier->transactionCaps()->sole();
        $this->assertSame('120000.00', $cap->max_cashback_per_transaction);
    }

    #[Test]
    public function editing_a_card_keeps_the_rules_it_did_not_touch(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $category = $this->makeSystemCategory();
        $combo = CategoryCombo::create([
            'scope' => CategoryCombo::SCOPE_USER,
            'owner_user_id' => $this->owner->id,
            'name' => 'Combo đi kèm '.uniqid(),
            'slug' => 'combo-'.uniqid(),
            'is_active' => true,
        ]);

        $cardId = (int) $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ hai rule',
                'policy' => [
                    'template_id' => $template->id,
                    'effective_from' => '2026-10-01',
                    'tiers' => [[
                        'name' => 'Bậc 1',
                        'min_total_spend' => 0,
                        'rules' => [[
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'category_id' => $category->id,
                            'cashback_percent' => 3,
                            'note' => 'giữ nguyên',
                        ], [
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'combo_id' => $combo->id,
                            'cashback_percent' => 5,
                        ]],
                    ]],
                ],
            ]
        )->assertCreated()->json('data.id');

        $policyId = (int) UserCard::findOrFail($cardId)->current_policy_id;
        $ruleIdsBefore = PolicyTierCategory::query()
            ->where('tier_id', PolicyTier::query()->where('policy_id', $policyId)->sole()->id)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        // Người dùng mở form (editor mang `id` của từng rule), đổi MỘT tỷ lệ rồi lưu.
        $tiers = $this->policyShowPayload($cardId, $policyId);

        $tiers['tiers'][0]['rules'][0]['cashback_percent'] = 6.5;

        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cards.update', $cardId),
            ['name' => 'Thẻ hai rule', 'policy' => $tiers]
        )->assertOk();

        $rules = PolicyTierCategory::query()
            ->where('tier_id', PolicyTier::query()->where('policy_id', $policyId)->sole()->id)
            ->orderBy('sort_order')
            ->get();

        // Sửa tại chỗ phải GIỮ id của các dòng còn tồn tại, để giao dịch đã finalize
        // vẫn trỏ đúng rule (FK `policy_tier_category_id` không bị set null).
        $this->assertSame(
            $ruleIdsBefore,
            $rules->pluck('id')->sort()->values()->all(),
            'Sửa cấu hình đã xoá tạo lại rule — giao dịch cũ mất liên kết với rule.'
        );

        $categoryRule = $rules->firstWhere('category_id', $category->id);
        $this->assertSame('6.500', $categoryRule->cashback_percent);
        $this->assertSame('giữ nguyên', $categoryRule->note, 'Ghi chú của rule không bị mất khi sửa tại chỗ.');

        $comboRule = $rules->first(fn (PolicyTierCategory $rule): bool => $rule->combo_id !== null);
        $this->assertNotNull($comboRule);
        $this->assertSame('5.000', $comboRule->cashback_percent, 'Rule không bị sửa vẫn phải giữ nguyên.');
        $this->assertSame($combo->id, (int) $comboRule->combo_id);

        // Chính sách vẫn là MỘT version sau khi sửa.
        $this->assertSame(
            1,
            Policy::query()->where('user_card_id', $cardId)->count()
        );
    }

    #[Test]
    public function picking_another_source_policy_clones_it_instead_of_editing_in_place(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();
        $otherTemplate = $this->makeSystemTemplateWithBlueprint();

        $cardId = (int) $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            ['name' => 'Thẻ đổi nguồn', 'policy' => ['template_id' => $template->id, 'effective_from' => '2026-10-01']]
        )->assertCreated()->json('data.id');

        $ownPolicyId = (int) UserCard::findOrFail($cardId)->current_policy_id;

        // Chọn mẫu KHÁC trong khu vực chỉnh sách rồi bấm "Lưu thẻ".
        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cards.update', $cardId),
            [
                'name' => 'Thẻ đổi nguồn',
                'policy' => [
                    'template_id' => $otherTemplate->id,
                    'effective_from' => '2026-11-01',
                    'tiers' => [['name' => 'Bậc từ mẫu mới', 'min_total_spend' => 0]],
                ],
            ]
        )->assertOk();

        $newPolicyId = (int) UserCard::findOrFail($cardId)->current_policy_id;

        // Chọn nguồn khác là SANG bản riêng mới (clone), không sửa tại chỗ bản cũ.
        $this->assertNotSame($ownPolicyId, $newPolicyId, 'Đổi nguồn phải tạo bản riêng mới cho thẻ.');
        $this->assertSame(
            (int) $otherTemplate->id,
            (int) Policy::findOrFail($newPolicyId)->template_id
        );
        $this->assertSame(
            'Bậc từ mẫu mới',
            PolicyTier::query()->where('policy_id', $newPolicyId)->sole()->name
        );

        // Bản riêng CŨ vẫn còn nguyên trong DB (không bị xoá), và thẻ trỏ sang bản mới.
        $this->assertSame(
            Policy::STATUS_ACTIVE,
            Policy::findOrFail($newPolicyId)->status
        );
    }

    #[Test]
    public function a_locked_policy_version_cannot_be_edited_in_place(): void
    {
        $template = $this->makeSystemTemplateWithBlueprint();

        $cardId = (int) $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            ['name' => 'Thẻ đã khoá', 'policy' => ['template_id' => $template->id, 'effective_from' => '2026-10-01']]
        )->assertCreated()->json('data.id');

        $policyId = (int) UserCard::findOrFail($cardId)->current_policy_id;
        Policy::query()->whereKey($policyId)->update(['is_locked' => true]);

        $this->actingAs($this->owner)->patchJson(
            route('credit-cards.api.cards.update', $cardId),
            [
                'name' => 'Thẻ đã khoá',
                'policy' => ['tiers' => [['name' => 'Cố sửa', 'min_total_spend' => 0]]],
            ]
        )->assertStatus(409);

        // Version bị khoá giữ nguyên, và tên thẻ cũng không đổi (cùng transaction).
        $this->assertSame(
            'Bậc cơ bản',
            PolicyTier::query()->where('policy_id', $policyId)->sole()->name
        );
        $this->assertSame('Thẻ đã khoá', UserCard::findOrFail($cardId)->name);
    }

    /**
     * Payload `policy` đúng như trình duyệt nhận được khi mở form Sửa thẻ.
     *
     * @return array<string, mixed>
     */
    private function policyShowPayload(int $cardId, int $policyId): array
    {
        $detail = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.policies.show', ['userCard' => $cardId, 'policy' => $policyId]))
            ->assertOk()
            ->json('data');

        return [
            'name' => $detail['name'],
            'effective_from' => $detail['effective_from'],
            'tiers' => $detail['tiers'],
        ];
    }

    #[Test]
    public function the_card_form_rejects_a_rule_that_targets_a_category_and_a_combo_at_once(): void
    {
        $category = $this->makeSystemCategory();

        $response = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ có rule mơ hồ',
                'policy' => [
                    'effective_from' => '2026-10-01',
                    'tiers' => [[
                        'name' => 'Bậc lỗi',
                        'rules' => [[
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'category_id' => $category->id,
                            'combo_id' => 999999,
                            'cashback_percent' => 2,
                        ]],
                    ]],
                ],
            ]
        );

        $response->assertStatus(422);

        $this->assertSame(
            0,
            UserCard::query()->where('name', 'Thẻ có rule mơ hồ')->count(),
            'Payload policy sai phải bị chặn trước khi insert thẻ.'
        );
    }

    #[Test]
    public function the_card_form_has_one_save_that_covers_card_and_policy(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        // Ô chọn mẫu + hai khối tóm tắt/chỉnh sửa đều phải có trong form.
        $this->assertStringContainsString('data-testid="policy-template-select"', $html);
        $this->assertStringContainsString('data-testid="policy-summary"', $html);
        $this->assertStringContainsString('data-testid="policy-editor"', $html);

        // Không còn bước "Áp dụng" riêng: bấm "Lưu thẻ" phải gửi cả `policy`.
        $this->assertStringNotContainsString('Lưu chính sách cho thẻ này', $html);
        $this->assertStringNotContainsString('Hãy lưu thẻ trước', $html);
        $this->assertStringContainsString('policyPayload', $html);
        $this->assertStringContainsString('loadDraftFromTemplate', $html);
    }

    #[Test]
    public function editing_a_card_locks_the_policy_until_the_edit_button_is_pressed(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Sửa thẻ mở ra ở chế độ chỉ đọc, có đúng MỘT nút mở khoá.
        $this->assertSame(1, $xpath->query("//*[@data-testid='policy-readonly']")->length);
        $this->assertSame(1, $xpath->query("//*[@data-testid='policy-edit-toggle']")->length);
        $this->assertStringContainsString('✏️ Chỉnh sửa', $html);

        // Không khoá bằng thuộc tính DOM tĩnh: bấm nút là mở được ngay, nên phải do
        // state quyết định — cụ thể là `viewMode` của chính Policy Editor.
        $this->assertStringContainsString('get policyReadonly()', $html);
        $this->assertStringContainsString('startPolicyEdit()', $html);
        $this->assertStringContainsString('this.policyEditor.viewMode = !this.policyEditMode', $html);

        // Mở form sửa thẻ phải vào chế độ chỉ đọc (`policyEditMode = false`); thêm thẻ
        // thì vào thẳng chế độ sửa vì chưa có policy nào để xem.
        $this->assertMatchesRegularExpression(
            '/openEdit\(id\)\s*\{.*?this\.policyEditMode = false.*?this\.loadPolicy\(card\);/s',
            $html,
            'Mở form sửa phải khoá chính sách trước khi nạp cấu hình.'
        );
        $this->assertMatchesRegularExpression(
            '/openCreate\(\)\s*\{.*?this\.policyEditMode = true/s',
            $html,
            'Thêm thẻ không có gì để xem nên phải vào thẳng chế độ sửa.'
        );

        // Nút "Lưu thẻ" phải đóng lại chế độ sửa, không để người dùng tưởng còn sửa
        // được sau khi đã lưu.
        $this->assertMatchesRegularExpression(
            '/this\.upsertCard\(saved\);.*?this\.policyEditMode = false.*?this\.loadPolicy\(saved\);/s',
            $html
        );
    }

    #[Test]
    public function the_readonly_mode_still_shows_every_tier_and_rule(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        // "Chỉ đọc" = KHOÁ ô nhập, không phải giấu cấu hình đi. Editor phải luôn render
        // và khoá bằng `viewMode` của chính state, nên đổi cờ là mở khoá ngay.
        $this->assertStringNotContainsString('x-show="!policyReadonly" class="space-y-4', $html);
        $this->assertMatchesRegularExpression(
            '/data-testid="policy-editor"/',
            $html
        );
        $this->assertStringNotContainsString('x-show="!policyReadonly" data-testid="policy-editor"', $html);

        // Mọi ô nhập của editor vẫn khoá theo cùng một cờ.
        $this->assertStringContainsString(':disabled="policyEditor.viewMode"', $html);
        $this->assertStringContainsString('x-show="!policyEditor.viewMode"', $html);

        // Còn ô chọn mẫu + hàng Lưu/Huỷ thì chỉ hiện khi đang sửa.
        $this->assertStringContainsString('x-show="!policyReadonly && form.id"', $html);
    }

    #[Test]
    public function the_policy_picker_only_appears_inside_the_editing_area(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $select = $xpath->query("//*[@data-testid='policy-template-select']")->item(0);
        $this->assertInstanceOf(DOMElement::class, $select);

        // Selector phải NẰM TRONG khu vực chỉnh sửa, không phải nằm trên đầu khu vực
        // chính sách: đi ngược lên cha, phải gặp `x-show="!policyReadonly"`.
        $ancestorToggles = '';
        for ($node = $select->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $ancestorToggles .= ' '.$node->getAttribute('x-show');
        }

        $this->assertStringContainsString(
            '!policyReadonly',
            $ancestorToggles,
            'Ô "Chọn chính sách" phải nằm trong khu vực chỉnh sửa, không đứng ở phía trên.'
        );

        // Không có hộp nhập "Tên chính sách"/"Ngày bắt đầu hiệu lực" ở form thẻ.
        $this->assertStringNotContainsString('id="cc-sp-name"', $html);
        $this->assertStringNotContainsString('id="cc-sp-from"', $html);

        // Nhưng tên vẫn phải hiện cho người dùng (ở tóm tắt), và payload vẫn mang
        // tên lấy từ state — bỏ ô nhập KHÔNG được biến thành mất tên.
        $this->assertStringContainsString('policyEditor.meta.name', $html);
        $this->assertStringContainsString('name: this.policyEditor.meta.name', $html);
    }

    #[Test]
    public function cancelling_the_policy_edit_restores_the_configuration_from_before(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        // Huỷ phải có ảnh chụp để trả lại, gồm cả mẫu đang chọn và version server đang
        // chạy — nếu thiếu thì đổi mẫu rồi huỷ sẽ lệch payload so với DB.
        $this->assertStringContainsString('policySnapshot', $html);
        $this->assertMatchesRegularExpression(
            '/startPolicyEdit\(\)\s*\{.*?this\.policySnapshot = \{.*?template_id: this\.policy\.template_id/s',
            $html,
            'Vào chế độ sửa phải chụp lại mẫu đang chọn.'
        );
        $this->assertMatchesRegularExpression(
            '/cancelPolicyEdit\(\)\s*\{.*?this\.policySnapshot = null;.*?this\.policyEditMode = false;.*?this\.policy\.template_id = snapshot\.template_id;.*?this\.policy\.current_version_id = snapshot\.current_version_id;.*?this\.mountPolicyEditor\(\{/s',
            $html,
            'Huỷ phải trả lại cả mẫu lẫn version đang chạy trước khi mount lại cấu hình.'
        );

        // Khu vực chính sách chỉ còn Huỷ, KHÔNG có nút lưu riêng: nút "Lưu thẻ"
        // dính đáy form là nút lưu DUY NHẤT (thẻ + policy trong một transaction).
        $this->assertStringContainsString('data-testid="policy-actions"', $html);
        $this->assertStringContainsString('Huỷ chỉnh sửa', $html);
        $this->assertMatchesRegularExpression(
            '/data-testid="policy-actions".*?@click="cancelPolicyEdit\(\)"/s',
            $html
        );

        $this->assertStringNotContainsString('💾 Lưu', $html);
        $this->assertStringNotContainsString('“Lưu” ở đây', $html);

        // (Việc "cả form chỉ có một nút submit" được khoá bằng DOM ở test dưới.)

        // Huỷ KHÔNG được gọi mạng: nó chỉ trả lại snapshot trên máy người dùng.
        $this->assertMatchesRegularExpression('/cancelPolicyEdit\(\)\s*\{/', $html);

        $cancelBody = substr(
            (string) strstr($html, 'cancelPolicyEdit() {'),
            0,
            2000
        );

        $this->assertStringContainsString(
            'policySnapshot = null',
            $cancelBody,
            'Huỷ phải xoá snapshot sau khi khôi phục.'
        );
        $this->assertStringNotContainsString(
            'this.request(',
            $cancelBody,
            'Huỷ phải thuần client, không gửi request nào.'
        );
    }

    #[Test]
    public function the_card_form_has_exactly_one_save_button_at_the_bottom(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Cả form chỉ có MỘT nút submit. Mọi nút khác phải là `type="button"` để
        // bấm nhầm không phát sinh request.
        $submits = $xpath->query("//form//button[@type='submit']");
        $this->assertSame(1, $submits->length, 'Form thẻ chỉ được có đúng một nút lưu.');

        $save = $submits->item(0);
        $this->assertStringContainsString('Lưu thẻ', $save->textContent);

        // Nút lưu nằm trong thanh hành động dính đáy form (chạm bằng ngón cái).
        $this->assertStringContainsString(
            'sticky',
            $save->parentNode->parentNode->getAttribute('class')
        );
    }

    #[Test]
    public function the_policy_summary_and_editor_never_open_a_second_horizontal_scroll(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Mọi khối policy phải là cột một trên mobile: không lưới cứng >2 cột và
        // không ô nhập bị ghim chiều rộng, ở BẤT KỲ node nào trong nhánh đó.
        foreach (['policy-summary', 'policy-editor', 'policy-readonly'] as $testId) {
            $nodes = $xpath->query("//*[@data-testid='{$testId}']");
            $this->assertGreaterThan(0, $nodes->length, "Thiếu khối {$testId}.");

            $node = $nodes->item(0);

            foreach ($this->selfAndDescendants($node) as $candidate) {
                $classes = $candidate->getAttribute('class');

                // `grid-cols-4` (không có tiền tố breakpoint) tràn ngang trên
                // mobile. `sm:grid-cols-3` thì không — mặc định vẫn 1 cột.
                $this->assertDoesNotMatchRegularExpression(
                    '/(?<!sm:|md:|lg:|xl:)\bgrid-cols-([3-9])\b/',
                    $classes,
                    "Khối {$testId} chứa lưới cứng nhiều cột, dễ tràn ngang trên mobile."
                );

                // Ô nhập bên trong editor không được ghim chiều rộng cứng.
                $this->assertDoesNotMatchRegularExpression(
                    '/\bw-(2[4-9]|[3-9][0-9])\b/',
                    $classes,
                    "Khối {$testId} chứa phần tử ghim chiều rộng, dễ tràn ngang trên mobile."
                );
            }

            // Vùng chứa phải co lại được, nếu không con flex sẽ đẩy ngang.
            $this->assertStringContainsString('min-w-0', $this->selfAndDescendantsClassText($node), "Thiếu `min-w-0` trong khối {$testId}.");
        }
    }

    #[Test]
    public function the_policy_summary_covers_tier_cap_and_every_rule_cap(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        // Tóm tắt phải nêu trần của bậc và trần chi tiết của từng quy tắc, không
        // chỉ tỷ lệ.
        $this->assertStringContainsString('tierCapLine', $html);
        $this->assertStringContainsString('ruleCapLine', $html);
        $this->assertStringContainsString('max_cashback_per_period', $html);
        $this->assertStringContainsString('max_cashback_per_transaction', $html);
        $this->assertStringContainsString('max_cashback_per_category_per_period', $html);

        // Trang thẻ dùng CHÍNH Policy Editor chính thức: state factory + hàm dựng
        // payload đều của partial dùng chung, không phải bản sao riêng. Trước đây
        // form thẻ có `toDraft()`/`policyPayload()` tự dựng, làm rơi
        // `transaction_caps` mỗi lần lưu lại.
        $this->assertStringContainsString('window.policyEditorState', $html);
        $this->assertStringContainsString('versionConfig()', $html);
        $this->assertStringContainsString('transaction_caps', $html);
        $this->assertStringNotContainsString('ruleForm', $html, 'Form thẻ không được còn form thêm quy tắc riêng.');

        // Các ô cap CỐ Ý dùng `x-model` trần, KHÔNG `.number`: ô để trống phải giữ
        // `''` để `num()` đổi thành `null` (= không giới hạn). Nếu dùng `.number`,
        // Alpine ép chuỗi rỗng thành số và trần "không giới hạn" thành 0 — tức là
        // "không được hoàn gì".
        $this->assertStringContainsString('x-model="rule.max_cashback_per_transaction"', $html);
        $this->assertStringContainsString('x-model="rule.max_cashback_per_category_per_period"', $html);
        $this->assertStringContainsString('x-model="tier.max_total_spend"', $html);
        $this->assertStringContainsString('x-model="tier.max_cashback_per_period"', $html);
        $this->assertStringContainsString('x-model="tier.name"', $html);
        $this->assertStringNotContainsString('x-model.number="rule.max_cashback_per_transaction"', $html);

        // Trần "không giới hạn" phải nói rõ thay vì để trống im lặng.
        $this->assertStringContainsString('Không giới hạn', $html);

        // Field KHÔNG có ô nhập vẫn phải đi kèm payload: lưu thẻ tạo version mới
        // bằng `replaceChildren()` (xoá rồi tạo lại dòng) nên thiếu chúng là mất
        // cấu hình cũ.
        $this->assertStringContainsString('spend_from: rule.spend_from ?? 0', $html);
        $this->assertStringContainsString('spend_to: rule.spend_to ??', $html);
        $this->assertStringContainsString('is_enabled: rule.is_enabled !== false', $html);
        $this->assertStringContainsString('note: rule.note ?? null', $html);
    }

    #[Test]
    public function the_rule_form_targets_keep_the_three_state_semantics(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.manage'))
            ->assertOk()
            ->getContent();

        // Ba trạng thái target nằm trong select "Phạm vi danh mục" của Policy Editor
        // chính thức (form thẻ dùng lại đúng select này).
        $this->assertStringContainsString('<option value="category">Danh mục cụ thể</option>', $html);
        $this->assertStringContainsString('<option value="combo">', $html);
        $this->assertStringContainsString('<option value="other">', $html);
        $this->assertStringContainsString('Combo danh mục', $html);
        $this->assertStringContainsString('Danh mục còn lại', $html);

        // Rule combo mang `scope_type = category`, nên nhận diện combo PHẢI dựa vào
        // `combo_id` — không được đoán bằng `scope_type`.
        $this->assertStringContainsString('function ruleTargetScope(rule)', $html);
        $this->assertStringContainsString('(rule.combo_id ?? null) !== null ? \'combo\' : \'category\'', $html);
        $this->assertStringNotContainsString(
            "combo_id: rule.scope_type === 'combo'",
            $html
        );

        // Payload chỉ mang MỘT trong hai target; fallback (`other`) không mang id nào.
        $this->assertStringContainsString('scope_type: isFallback ? \'other\' : \'category\'', $html);
        $this->assertStringContainsString(
            'category_id: isFallback || useCombo ? null : this.targetId(rule.category_id)',
            $html
        );
        $this->assertStringContainsString(
            'combo_id: isFallback || ! useCombo ? null : this.targetId(rule.combo_id)',
            $html
        );
    }

    /**
     * Node này và toàn bộ hậu duệt — dùng để soi một nhánh con trong DOM.
     *
     * @return array<int, DOMElement>
     */
    private function selfAndDescendants(DOMNode $node): array
    {
        $found = [];

        if ($node instanceof DOMElement) {
            $found[] = $node;
        }

        foreach ($node->childNodes ?? [] as $child) {
            foreach ($this->selfAndDescendants($child) as $descendant) {
                $found[] = $descendant;
            }
        }

        return $found;
    }

    /**
     * Gộp class của node + hậu duệt thành một chuỗi để assert đơn giản.
     */
    private function selfAndDescendantsClassText(DOMNode $node): string
    {
        $classes = '';

        foreach ($this->selfAndDescendants($node) as $element) {
            $classes .= ' '.$element->getAttribute('class');
        }

        return $classes;
    }

    #[Test]
    public function a_combo_rule_saved_from_the_card_form_keeps_its_combo(): void
    {
        $combo = CategoryCombo::create([
            'scope' => CategoryCombo::SCOPE_USER,
            'owner_user_id' => $this->owner->id,
            'name' => 'Combo ăn chơi '.uniqid(),
            'slug' => 'combo-'.uniqid(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ có rule combo',
                'policy' => [
                    'effective_from' => '2026-10-01',
                    'tiers' => [[
                        'name' => 'Bậc combo',
                        'rules' => [[
                            // Rule combo mang `scope_type = category` — dùng
                            // `scope_type` để nhận diện combo sẽ rơi về null.
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'combo_id' => $combo->id,
                            'cashback_percent' => 5,
                        ]],
                    ]],
                ],
            ]
        );

        $response->assertCreated();

        $policyId = (int) $response->json('data.policy.id');
        $tier = PolicyTier::query()->where('policy_id', $policyId)->sole();
        $rules = PolicyTierCategory::query()->where('tier_id', $tier->id)->get();

        // Tier tự sinh thêm 1 fallback ⇒ có 2 rule, nên lấy đúng rule combo.
        $rule = $rules->firstWhere('combo_id', $combo->id);
        $this->assertNotNull($rule, 'Rule combo phải được tạo.');
        $this->assertTrue($rule->isComboSpecific(), 'Rule combo phải nhận diện được là combo.');
        $this->assertSame($combo->id, (int) $rule->combo_id);
        $this->assertNull($rule->category_id, 'Rule combo không được mang category_id.');
        $this->assertSame('5.000', $rule->cashback_percent);
        $this->assertSame(
            1,
            $rules->where('category_id', null)->where('combo_id', null)->count(),
            'Chỉ fallback mới được phép trống cả hai target.'
        );
    }

    #[Test]
    public function a_rule_of_another_users_combo_is_rejected(): void
    {
        $intruder = User::factory()->create();
        $combo = CategoryCombo::create([
            'scope' => CategoryCombo::SCOPE_USER,
            'owner_user_id' => $intruder->id,
            'name' => 'Combo của người khác '.uniqid(),
            'slug' => 'combo-'.uniqid(),
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)->postJson(
            route('credit-cards.api.cards.store'),
            [
                'name' => 'Thẻ cướp combo',
                'policy' => [
                    'effective_from' => '2026-10-01',
                    'tiers' => [[
                        'name' => 'Bậc cướp',
                        'rules' => [[
                            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
                            'combo_id' => $combo->id,
                            'cashback_percent' => 5,
                        ]],
                    ]],
                ],
            ]
        )->assertStatus(422);

        $this->assertSame(
            0,
            UserCard::query()->where('name', 'Thẻ cướp combo')->count(),
            'Combo của user khác phải bị chặn trước khi insert thẻ.'
        );
    }

    /**
     * Template hệ thống + blueprint version 1 có 1 bậc / 1 quy tắc danh mục.
     */
    private function makeSystemTemplateWithBlueprint(): PolicyTemplate
    {
        $template = $this->makeSystemTemplate(['name' => 'Chính sách hệ thống '.uniqid()]);
        $category = $this->makeSystemCategory();

        $blueprint = Policy::create([
            'template_id' => $template->id,
            'user_card_id' => null,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $template->name,
            'effective_from' => '2026-01-01',
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);
        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        $tier = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Bậc cơ bản',
            'sort_order' => 0,
            'min_total_spend' => 0,
            'max_total_spend' => null,
        ]);

        PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $category->id,
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'sort_order' => 0,
            'cashback_percent' => 2,
            'is_enabled' => true,
        ]);

        $template->forceFill(['default_version_id' => $blueprint->id])->save();

        return $template->refresh();
    }

    /**
     * Template RIÊNG của một user + blueprint tương ứng.
     */
    private function makeUserTemplateWithBlueprint(int $ownerId, string $name): PolicyTemplate
    {
        $template = PolicyTemplate::create([
            'scope' => PolicyTemplate::SCOPE_USER,
            'owner_user_id' => $ownerId,
            'name' => $name,
            'slug' => 'user-template-'.uniqid(),
            'is_builtin' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $category = $this->makeSystemCategory();

        $blueprint = Policy::create([
            'template_id' => $template->id,
            'user_card_id' => null,
            'version_no' => 1,
            'status' => Policy::STATUS_ACTIVE,
            'name' => $name,
            'effective_from' => CarbonImmutable::now()->toDateString(),
            'min_total_spend' => 0,
            'rounding_mode' => 'round',
        ]);
        $blueprint->forceFill(['root_policy_id' => $blueprint->id])->save();

        $tier = PolicyTier::create([
            'policy_id' => $blueprint->id,
            'name' => 'Bậc cơ bản',
            'sort_order' => 0,
            'min_total_spend' => 0,
            'max_total_spend' => null,
        ]);

        PolicyTierCategory::create([
            'tier_id' => $tier->id,
            'category_id' => $category->id,
            'scope_type' => PolicyTierCategory::SCOPE_CATEGORY,
            'sort_order' => 0,
            'cashback_percent' => 1.5,
            'is_enabled' => true,
        ]);

        $template->forceFill(['default_version_id' => $blueprint->id])->save();

        return $template->refresh();
    }
}
