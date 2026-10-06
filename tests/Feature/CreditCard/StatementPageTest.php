<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\CreditCardStatement;
use App\Models\CreditCard\StatementPeriod;
use App\Models\CreditCard\UserCard;
use App\Models\User;
use App\Services\CreditCard\CreditCardStatementService;
use App\Services\CreditCard\StatementPeriodService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang Sao kê thực tế — /thetindung/sao-ke.
 *
 * Bảy bất biến được khoá ở đây:
 *   1. MỞ TRANG KHÔNG ĐƯỢC GHI. Thẻ chưa có kỳ vẫn hiện dòng, nhưng mở trang
 *      không được sinh bản ghi kỳ — xem `CreditCardModuleTest` cho cùng nguyên tắc.
 *   2. MỖI THẺ MỘT DÒNG, kể cả thẻ chưa nhập sao kê: để so sánh được ngay.
 *   3. CHỈ THẺ CỦA CHÍNH MÌNH. Không thấy thẻ người khác kể cả khi đoán đúng id.
 *   4. MỖI CẶP (THẺ, KỲ) MỘT DÒNG. Nhập lại ghi đè, không sinh dòng thứ hai.
 *   5. `closing_balance` LUÔN DO SERVER TÍNH. Client gửi lên cũng bị bỏ qua.
 *   6. SỬA THEO TỪNG PHẦN. Sửa riêng `actual_reward` không được làm
 *      `actual_spend` rơi về 0 — mất tiền thật.
 *   7. KỲ ĐÃ CHỐT là bản ghi lịch sử: sửa và xoá đều bị chặn, và UI nói rõ lý do.
 *
 * Sao kê thực tế KHÁC lịch sử giao dịch: nó không chạy engine cashback, nên test
 * này cố tình KHÔNG dựng policy/tier/rule cho thẻ — nếu sao kê có chạm vào
 * engine, số liệu ở đây đã sai và test sẽ đỏ.
 */
class StatementPageTest extends TestCase
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
    // Quyền truy cập
    // =====================================================================

    #[Test]
    public function it_requires_authentication(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->get(route('credit-cards.statements'))
            ->assertRedirect(route('login'));

        $this->getJson(route('credit-cards.api.statements.index'))
            ->assertUnauthorized();

        $this->postJson(route('credit-cards.api.statements.store', $card->id), [
            'actual_spend' => '1000',
            'actual_reward' => '0',
        ])->assertUnauthorized();
    }

    #[Test]
    public function it_never_lists_another_users_card(): void
    {
        $mine = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ của tôi']);
        $theirs = $this->makeUserCard($this->stranger->id, ['name' => 'Thẻ của người khác']);

        $this->createStatement($mine, '1000000', '50000');
        $this->createStatement($theirs, '9000000', '450000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Thẻ của tôi', $html);
        $this->assertStringNotContainsString('Thẻ của người khác', $html);
        $this->assertStringNotContainsString('9.000.000', $html);

        // API phải khoá cùng phạm vi với HTML — cùng một hàm dựng dữ liệu.
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Thẻ của tôi');
    }

    #[Test]
    public function another_user_cannot_write_a_statement_of_my_card(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        // 422 chứ không phải 403: `bootstrap/app.php` cố ý ánh xạ
        // `InvalidArgumentException` của service thành 422 cho `credit-cards.api.*`
        // — "thẻ của người khác" là dữ liệu sai, còn 403 dành cho đã xác định
        // được quyền rồi mới bị từ chối. Cùng cách với thẻ/sao kê khác trong module.
        $this->actingAs($this->stranger)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '1',
                'actual_reward' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Thẻ tín dụng không tồn tại hoặc không thuộc về bạn.');

        $this->assertSame(0, CreditCardStatement::query()->count());
    }

    // =====================================================================
    // Mở trang không được ghi
    // =====================================================================

    #[Test]
    public function opening_the_page_never_creates_a_statement_period(): void
    {
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A']);
        $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);

        $this->assertSame(0, StatementPeriod::query()->count());

        $this->actingAs($this->owner)->get(route('credit-cards.statements'))->assertOk();
        $this->actingAs($this->owner)->getJson(route('credit-cards.api.statements.index'))->assertOk();

        $this->assertSame(
            0,
            StatementPeriod::query()->count(),
            'Chỉ đọc thì không được sinh bản ghi kỳ.',
        );
    }

    #[Test]
    public function every_card_gets_a_row_with_its_default_period_even_without_a_statement(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ chưa nhập']);

        [$completedStart, $completedEnd] = app(StatementPeriodService::class)->completedBoundaries(
            $card,
            CarbonImmutable::now(),
        );

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // Chưa có bản ghi kỳ, nhưng vẫn phải nói rõ đang ở trong kỳ nào.
            ->assertJsonPath('data.0.period.id', null)
                ->assertJsonPath('data.0.statement', null)
                // Mặc định là KỲ ĐÃ KẾT THÚC GẦN NHẤT, không phải kỳ hiện tại:
                // người dùng mở trang để nhập bảng kê ngân hàng đã phát hành.
                ->assertJsonPath('data.0.period.period_start', $completedStart->toDateString())
                ->assertJsonPath('data.0.period_bounds.start', $completedStart->toDateString())
                ->assertJsonPath('data.0.period_bounds.end', $completedEnd->toDateString());
    }

    #[Test]
    public function the_default_period_is_the_latest_completed_one_not_the_current_one(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $completed = $this->completedPeriodStart($card);
        $current = $this->currentPeriodStart($card);

        // Hai kỳ khác nhau — không có cách nào "trùng khéo" để test này xanh
        // nhầm khi ai đó đổi mặc định về kỳ hiện tại.
        $this->assertNotSame($completed, $current);

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index'))
            ->assertOk()
            ->assertJsonPath('data.0.period.period_start', $completed);
    }

    #[Test]
    public function the_period_selector_offers_the_current_and_historical_periods(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $data = $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index'))
            ->assertOk()
            ->json('data.0.periods');

        $this->assertNotEmpty($data, 'Dropdown phải có ít nhất kỳ hiện tại.');

        // Mới nhất trước: phần tử đầu là kỳ hiện tại.
        $this->assertTrue($data[0]['is_current'], 'Kỳ mới nhất trong dropdown phải là kỳ hiện tại.');
        $this->assertSame($this->currentPeriodStart($card), $data[0]['period_start']);

        // Mọi kỳ trong dropdown đều là kỳ hợp lệ của chính thẻ này — suy ra
        // từ chu kỳ của thẻ, không phải lấy từ bảng kỳ (dropdown phải đầy kể cả
        // khi thẻ chưa có bản ghi kỳ nào).
        foreach ($data as $option) {
            $this->assertTrue(
                app(CreditCardStatementService::class)->isSelectablePeriodStart($card, $option['period_start']),
                'Mọi kỳ trong dropdown phải là kỳ hợp lệ của thẻ.',
            );
        }
    }

    #[Test]
    public function opening_the_page_never_creates_a_period_even_with_a_selector(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $current = $this->currentPeriodStart($card);

        $this->assertSame(0, StatementPeriod::query()->count());

        // Chọn một kỳ CHƯA có bản ghi: vẫn không được sinh bản ghi kỳ.
        $this->actingAs($this->owner)
            ->get(route('credit-cards.statements', ['period' => [$card->id => $current]]))
            ->assertOk();

        $this->assertSame(
            0,
            StatementPeriod::query()->count(),
            'Xem một kỳ chưa có bản ghi thì không được tạo bản ghi kỳ đó.',
        );
    }

    #[Test]
    public function selecting_a_period_switches_the_row_and_keeps_each_card_separate(): void
    {
        $cardA = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A']);
        $cardB = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);

        // Mỗi thẻ một chu kỳ khác nhau để chọn nhầm là thấy ngay.
        $cardA->forceFill([
            'statement_period_start' => '2026-01-05',
            'statement_day' => 5,
        ])->save();
        $cardB->forceFill([
            'statement_period_start' => '2026-01-20',
            'statement_day' => 20,
        ])->save();

        $completedA = $this->completedPeriodStart($cardA->refresh());
        $currentA = $this->currentPeriodStart($cardA->refresh());

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index', [
                'period' => [$cardA->id => $currentA],
            ]))
            ->assertOk()
            // Chọn kỳ riêng cho thẻ A ⇒ dòng của A đổi kỳ, dòng của B giữ mặc
            // định. Không có một lựa chọn dùng chung cho cả hai.
            ->assertJsonPath('data.0.id', $cardA->id)
            ->assertJsonPath('data.0.period.period_start', $currentA)
            ->assertJsonPath('data.1.id', $cardB->id)
            ->assertJsonPath('data.1.period.period_start', $this->completedPeriodStart($cardB->refresh()));

        // Một `period_start` không thuộc chu kỳ của thẻ thì bị bỏ qua và rơi về
        // kỳ mặc định — không phải kỳ lân cận nhất của ngày đó.
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index', [
                'period' => [$cardA->id => '2026-01-06'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.period.period_start', $completedA);
    }

    #[Test]
    public function a_period_of_another_card_cannot_be_written_through_its_own_period_start(): void
    {
        $cardA = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ A']);
        $cardB = $this->makeUserCard($this->owner->id, ['name' => 'Thẻ B']);

        // `period_start` là NGÀY MỞ KỲ, không phải id bản ghi kỳ — nên gửi kỳ
        // của thẻ khác chỉ có thể được từ chối, không thể ghi nhầm tiền sang thẻ
        // đó. Ở đây hai thẻ cùng chu kỳ nên ngày là hợp lệ với thẻ nhận.
        $periodStart = $this->completedPeriodStart($cardA);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $cardA->id), [
                'period_start' => $periodStart,
                'actual_spend' => '1000000',
                'actual_reward' => '0',
            ])
            ->assertCreated();

        // Dòng chỉ nằm ở thẻ đã POST, không "nhảy" sang thẻ khác.
        $this->assertSame(1, CreditCardStatement::query()->count());
        $this->assertSame(
            (int) $cardA->id,
            (int) CreditCardStatement::query()->firstOrFail()->user_card_id,
        );
    }

    // =====================================================================
    // Ghi sao kê
    // =====================================================================

    #[Test]
    public function it_stores_the_default_period_and_computes_the_closing_balance(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '5000000',
                'actual_reward' => '250000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.actual_spend', '5000000.00')
            ->assertJsonPath('data.actual_reward', '250000.00')
            // 5.000.000 − 250.000
            ->assertJsonPath('data.closing_balance', '4750000.00');

        // Không gửi `period_start` ⇒ ghi vào KỲ MẶC ĐỊNH (đã kết thúc gần nhất),
        // và chỉ một dòng cho cặp (thẻ, kỳ).
        $period = StatementPeriod::query()->where('user_card_id', $card->id)->firstOrFail();

        $this->assertSame($this->completedPeriodStart($card), $period->period_start->toDateString());
        $this->assertSame(1, CreditCardStatement::query()->count());
        $this->assertSame(
            '4750000.00',
            (string) CreditCardStatement::query()->firstOrFail()->closing_balance,
        );
        $this->assertSame($period->id, CreditCardStatement::query()->firstOrFail()->statement_period_id);
    }

    #[Test]
    public function it_stores_the_chosen_period_including_a_historical_one(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        // Kỳ cách xa hơn kỳ mặc định: chọn được kỳ đã qua, không chỉ kỳ vừa
        // kết thúc hay kỳ hiện tại.
        $historical = app(StatementPeriodService::class)
            ->selectableBoundaries($card, CarbonImmutable::now(), 6)[3][0]
            ->toDateString();

        $this->assertNotSame($this->completedPeriodStart($card), $historical);
        $this->assertNotSame($this->currentPeriodStart($card), $historical);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'period_start' => $historical,
                'actual_spend' => '3000000',
                'actual_reward' => '100000',
            ])
            ->assertCreated()
            ->assertJsonPath('data.closing_balance', '2900000.00');

        $this->assertSame(
            $historical,
            StatementPeriod::query()->where('user_card_id', $card->id)->firstOrFail()->period_start->toDateString(),
            'Sao kê phải nằm ở đúng kỳ người dùng chọn.',
        );
    }

    #[Test]
    public function each_period_holds_exactly_one_statement_row(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $historical = app(StatementPeriodService::class)
            ->selectableBoundaries($card, CarbonImmutable::now(), 6)[2][0]
            ->toDateString();

        // Cùng thẻ, hai kỳ khác nhau ⇒ hai dòng riêng, không dùng chung.
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'period_start' => $historical,
                'actual_spend' => '1000000',
                'actual_reward' => '0',
            ])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'period_start' => $this->completedPeriodStart($card),
                'actual_spend' => '2000000',
                'actual_reward' => '0',
            ])
            ->assertCreated();

        $this->assertSame(2, CreditCardStatement::query()->count());

        // Nhập lại đúng một kỳ đã có ⇒ SỬA dòng đó, không thêm dòng thứ ba.
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'period_start' => $historical,
                'actual_spend' => '1500000',
                'actual_reward' => '0',
            ])
            ->assertOk();

        $this->assertSame(2, CreditCardStatement::query()->count());
    }

    #[Test]
    public function a_period_start_outside_the_card_cycle_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        // Ngày nằm trong tháng nhưng KHÔNG phải ngày mở kỳ của thẻ: nhận nhầm
        // kỳ sẽ ghi tiền vào kỳ lân cận mà người dùng không hề thấy.
        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'period_start' => '2026-01-06',
                'actual_spend' => '1000000',
                'actual_reward' => '0',
            ])
            ->assertStatus(422);

        $this->assertSame(0, CreditCardStatement::query()->count());
        $this->assertSame(0, StatementPeriod::query()->count());
    }

    #[Test]
    public function a_malformed_period_start_is_rejected_by_validation(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'period_start' => '01/02/2026',
                'actual_spend' => '1000000',
                'actual_reward' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('period_start');

        $this->assertSame(0, CreditCardStatement::query()->count());
    }

    #[Test]
    public function a_closing_balance_sent_by_the_client_is_ignored(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '1000000',
                'actual_reward' => '0',
                // Client tự tính và gửi số không: phải bị bỏ qua.
                'closing_balance' => '0.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.closing_balance', '1000000.00');

        $this->assertSame(
            '1000000.00',
            (string) CreditCardStatement::query()->firstOrFail()->closing_balance,
        );
    }

    #[Test]
    public function storing_twice_updates_the_same_row_instead_of_adding_one(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '1000000',
                'actual_reward' => '0',
            ])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '2000000',
                'actual_reward' => '100000',
            ])
            ->assertOk();

        $this->assertSame(
            1,
            CreditCardStatement::query()->count(),
            'Sửa một kỳ phải ghi đè, không sinh dòng thứ hai.',
        );

        $this->assertSame('2000000.00', (string) CreditCardStatement::query()->firstOrFail()->actual_spend);
    }

    #[Test]
    public function a_reward_greater_than_the_spend_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '1000',
                'actual_reward' => '2000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('actual_reward');

        $this->assertSame(0, CreditCardStatement::query()->count());
    }

    #[Test]
    public function negative_amounts_are_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '-1000',
                'actual_reward' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('actual_spend');
    }

    // =====================================================================
    // `actual_reward = 0` LÀ GIÁ TRỊ HỢP LỆ, KHÔNG PHẢI "CHƯA NHẬP"
    // =====================================================================
    //
    // 0 rất dễ bị nhầm là "trống": PHP/JS đều coi falsy, và rule kiểu `gt:0` sẽ
    // loại nó. Dưới đây khoá lại đúng ranh giới này ở CẢ HAI đầu (POST và
    // PATCH), vì đây là tiền thật: số 0 phải ghi được, còn ô rỗng thì phải bị
    // chặn — hai thứ đó là khác nhau, dù nhìn có thể giống nhau.

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function rewardBoundaries(): array
    {
        return [
            // 0 ở mọi hình dạng mà trình duyệt có thể gửi: chuỗi "0", chuỗi
            // "0.00", số 0, số thực 0.0, và chuỗi có khoảng trắng quanh.
            // Trước đây có code đọc bằng phép ép kiểu lỏng (`(float) $v ?: ''`)
            // khiến 0 thành rỗng; các hình dạng này chặn đúng đường đó.
            'chuỗi "0"' => ['0', true],
            'chuỗi "0.00"' => ['0.00', true],
            'số nguyên 0' => [0, true],
            'số thực 0.0' => [0.0, true],
            'chuỗi " 0 "' => [' 0 ', true],

            // Rỗng = chưa nhập ⇒ phải chặn, và phải nói đúng lý do.
            'chuỗi rỗng' => ['', false],
            'null' => [null, false],
        ];
    }

    #[Test]
    #[DataProvider('rewardBoundaries')]
    public function zero_reward_is_a_valid_value_and_empty_is_not(mixed $reward, bool $shouldPass): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $response = $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '10000000',
                'actual_reward' => $reward,
            ]);

        if (! $shouldPass) {
            $response->assertStatus(422)->assertJsonValidationErrors('actual_reward');

            $this->assertSame(0, CreditCardStatement::query()->count());

            return;
        }

        $response->assertSuccessful();

        // 0 là số 0 thật: không phải null, không phải rỗng.
        $this->assertSame('0.00', $response->json('data.actual_reward'));
        $this->assertSame('10000000.00', $response->json('data.closing_balance'));
    }

    #[Test]
    public function zero_reward_can_also_be_saved_when_editing(): void
    {
        // Đường PATCH dùng `sometimes` + `withValidator()` thay vì `lte:` nên
        // phải kiểm riêng: sửa một dòng vốn đã có hoàn/thưởng xuống 0 là chuyện
        // rất thường (người dùng nhập nhầm rồi ghi đè), và nó phải được phép.
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '10000000', '500000');

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.statements.update', $statement->id), [
                'actual_reward' => '0',
            ])
            ->assertOk()
            ->assertJsonPath('data.actual_reward', '0.00')
            ->assertJsonPath('data.closing_balance', '10000000.00');

        $this->assertSame('0.00', (string) $statement->refresh()->actual_reward);
    }

    #[Test]
    public function a_zero_spend_with_a_zero_reward_is_accepted(): void
    {
        // 0/0 là việc hợp lệ: kỳ không chi và không có hoàn. Chỉ khi nào
        // actual_reward > actual_spend mới bị chặn, nên 0 không vượt 0.
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '0',
                'actual_reward' => '0',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.actual_spend', '0.00')
            ->assertJsonPath('data.actual_reward', '0.00')
            ->assertJsonPath('data.closing_balance', '0.00');
    }

    #[Test]
    public function a_negative_reward_is_rejected_even_though_zero_is_accepted(): void
    {
        // Đổi dấu `min:0` thành `gt:0` (hoặc kẹp sai ở JS) sẽ làm test này đỏ —
        // đó là chốt chặn cho đúng lỗi mà báo cáo nêu.
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '10000000',
                'actual_reward' => '-1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('actual_reward');

        $this->assertSame(0, CreditCardStatement::query()->count());
    }

    #[Test]
    public function a_reward_above_a_zero_spend_is_rejected(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->postJson(route('credit-cards.api.statements.store', $card->id), [
                'actual_spend' => '0',
                'actual_reward' => '1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('actual_reward');
    }

    // =====================================================================
    // Sửa
    // =====================================================================

    #[Test]
    public function editing_only_the_reward_keeps_the_spend(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.statements.update', $statement->id), [
                'actual_reward' => '500000',
            ])
            ->assertOk()
            ->assertJsonPath('data.actual_spend', '5000000.00')
            ->assertJsonPath('data.actual_reward', '500000.00')
            ->assertJsonPath('data.closing_balance', '4500000.00');

        $statement->refresh();

        $this->assertSame('5000000.00', (string) $statement->actual_spend);
    }

    #[Test]
    public function another_user_cannot_edit_or_delete_my_statement(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $this->actingAs($this->stranger)
            ->patchJson(route('credit-cards.api.statements.update', $statement->id), [
                'actual_spend' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->deleteJson(route('credit-cards.api.statements.destroy', $statement->id))
            ->assertForbidden();

        $this->assertSame('5000000.00', (string) $statement->refresh()->actual_spend);
    }

    #[Test]
    public function it_deletes_a_statement(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.statements.destroy', $statement->id))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, CreditCardStatement::query()->count());

        // Thẻ vẫn còn, chỉ mất dòng sao kê.
        $this->assertNotNull(UserCard::query()->find($card->id));
    }

    // =====================================================================
    // Kỳ đã chốt
    // =====================================================================

    #[Test]
    public function a_statement_of_a_finalized_period_cannot_be_edited_or_deleted(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $statement->statementPeriod
            ->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])
            ->save();

        $this->actingAs($this->owner)
            ->patchJson(route('credit-cards.api.statements.update', $statement->id), [
                'actual_spend' => '1',
            ])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->deleteJson(route('credit-cards.api.statements.destroy', $statement->id))
            ->assertForbidden();

        $this->assertSame('5000000.00', (string) $statement->refresh()->actual_spend);
        $this->assertSame(1, CreditCardStatement::query()->count());
    }

    #[Test]
    public function a_finalized_period_row_is_locked_and_explains_why(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $statement = $this->createStatement($card, '5000000', '250000');

        $statement->statementPeriod
            ->forceFill(['status' => StatementPeriod::STATUS_FINALIZED])
            ->save();

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Nút sửa không còn, lý do nói rõ — không chỉ làm mờ nút.
        $this->assertStringNotContainsString(
            "data-testid=\"statement-edit-{$card->id}\"",
            $html,
        );
        $this->assertStringContainsString('Kỳ đã chốt, không sửa được', $html);
    }

    // =====================================================================
    // Bảo mật hiển thị
    // =====================================================================

    #[Test]
    public function it_never_shows_a_full_card_number(): void
    {
        $card = $this->makeUserCard($this->owner->id, ['card_number_last4' => '9876']);
        $this->createStatement($card, '1000000', '0');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('•••• 9876', $html);
        // Module không lưu số thẻ đầy đủ ở đâu cả; chặn thêm ở tầng trình bày.
        $this->assertStringNotContainsString('card_number"', $html);
    }

    #[Test]
    public function it_does_not_leak_cashback_calculation_internals(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '5000000', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Sao kê thực tế KHÔNG chạy engine: không có %/rule/tier/eligible ở đây.
        $this->assertStringNotContainsString('cashback_percent', $html);
        $this->assertStringNotContainsString('policy_tier', $html);
        $this->assertStringNotContainsString('eligible_spend', $html);
        $this->assertStringNotContainsString('rounding_mode', $html);
    }

    #[Test]
    public function the_browser_never_computes_the_closing_balance_itself(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '5000000', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Không có phép trừ nào giữa hai ô nhập trong JavaScript: `closing_balance` chỉ
        // được đọc từ số server gửi. Hai nơi cùng công thức là chỗ sinh lệch.
        $this->assertStringContainsString(
            'Số tiền còn phải trả sẽ được hệ thống tính',
            $html,
            'Form phải nói trước số dư được tính ở server, không phải ngay trên trang.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/actual_spend[\s\S]{0,240}?\s-\s[\s\S]{0,240}?actual_reward/',
            $this->codeOf($this->scriptOf($html)),
            'Không được tự tính số dư còn phải trả trong trình duyệt.',
        );
    }

    #[Test]
    public function the_reward_box_does_not_look_like_it_already_holds_a_zero(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // `placeholder="0"` trông y hệt một số 0 đã nhập, nhưng nó không phải giá
        // trị: người dùng thấy "0" trong ô rồi bấm lưu thì server nhận chuỗi rỗng
        // và trả lời "Vui lòng nhập số tiền hoàn/thưởng thực tế trong kỳ." — tức
        // báo nhầm rằng 0 là không hợp lệ. Ô phải nói rõ 0 là một lựa chọn hợp lệ.
        $this->assertStringNotContainsString(
            'placeholder="0"',
            $html,
            'Placeholder dạng số trần dễ bị đọc nhầm thành giá trị đã nhập.',
        );

        $rewardInput = $this->tagWithName($html, 'actual_reward');

        $this->assertStringContainsString(
            'placeholder="Nhập 0 nếu không có hoàn"',
            $rewardInput,
            'Ô hoàn/thưởng phải nói rõ số 0 là giá trị hợp lệ.',
        );
    }

    /**
     * Thẻ mở `<input name="...">` — cắt từ `<input` gần nhất tới `>` kế tiếp.
     */
    private function tagWithName(string $html, string $name): string
    {
        $needle = "name=\"{$name}\"";
        $position = strpos($html, $needle);

        $this->assertNotFalse($position, "Không tìm thấy {$needle}");

        $open = strrpos(substr($html, 0, $position), '<input');
        $close = strpos($html, '>', $position);

        $this->assertNotFalse($open);
        $this->assertNotFalse($close);

        return substr($html, (int) $open, (int) $close - (int) $open + 1);
    }

    #[Test]
    public function a_row_records_the_statement_id_the_server_just_created(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '5000000', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $script = $this->codeOf($this->scriptOf($html));

        // Sau lần NHẬP đầu tiên, dòng phải nhớ id server vừa cấp. Nếu không ghi,
        // `statementUrl()` vẫn trả POST ở lần sửa kế tiếp và đụng UNIQUE
        // (mỗi cặp thẻ/kỳ chỉ được có một dòng) — tức nhập xong sửa tiếp là lỗi.
        $this->assertMatchesRegularExpression(
            '/closing\.dataset\.statementId\s*=\s*String\(\s*statement\.id/',
            $script,
            'Dòng phải ghi lại id do server trả về sau khi nhập.',
        );
    }

    #[Test]
    public function clearing_a_row_forgets_the_id_so_the_next_entry_posts_again(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '5000000', '250000');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $script = $this->codeOf($this->scriptOf($html));

        // Xoá xong mà còn sót id thì lần mở kế tiếp sẽ PATCH tới dòng không còn
        // tồn tại. Ô nhập, nhãn nút và nút "Xoá" cũng phải về trạng thái "chưa
        // nhập" để mô tả đúng dòng.
        $this->assertMatchesRegularExpression(
            '/closing\.dataset\.statementId\s*=\s*\'\'/',
            $script,
            'Xoá sao kê phải xoá luôn id trên DOM.',
        );
        $this->assertMatchesRegularExpression(
            '/input\.value\s*=\s*\'\'/',
            $script,
            'Xoá sao kê phải xoá luôn số đã nhập trong hai ô.',
        );
        $this->assertMatchesRegularExpression(
            '/edit\.textContent\s*=\s*\'Nhập sao kê\'/',
            $script,
            'Nhãn nút phải trở lại "Nhập sao kê".',
        );
        // Nút "Xoá" được ẨN chứ không gỡ khỏi DOM: nó luôn render sẵn để khi
        // người dùng đánh dấu "đã trả" ở kỳ chưa có dòng thì nút xuất hiện ngay
        // trong phiên đó. Node dựng tay bằng `document.createElement` sẽ không qua
        // `Alpine.initTree()` nên mất luôn `@click` — đó là lý do dùng `hidden`.
        $this->assertMatchesRegularExpression(
            '/statement-delete-[\s\S]*?classList\.toggle\(\'hidden\'/',
            $script,
            'Nút "Xoá" phải được ẩn khi dòng không còn sao kê.',
        );
    }

    #[Test]
    public function the_page_renders_each_amount_with_exactly_one_currency_suffix(): void
    {
        $card = $this->makeUserCard($this->owner->id);
        $this->createStatement($card, '7310000', '365500');

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $text = $this->visibleTextOf($html);

        // 7.310.000 − 365.500 = 6.944.500
        $this->assertStringContainsString('7.310.000', $text);
        $this->assertStringContainsString('365.500', $text);
        $this->assertStringContainsString('6.944.500', $text);

        // "đ đ" là text node xen giữa các thẻ <span> nên phải bóc thẻ mới thấy.
        $this->assertStringNotContainsString('đ đ', $text);
    }

    #[Test]
    public function a_missing_statement_reads_as_not_entered_rather_than_zero(): void
    {
        $card = $this->makeUserCard($this->owner->id);

        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        // Chưa có sao kê ≠ sao kê bằng 0.
        $this->assertStringContainsString('Chưa nhập', $html);
        $this->assertStringNotContainsString('Còn phải trả 0', $html);
    }

    // =====================================================================
    // Sắp xếp
    // =====================================================================

    #[Test]
    public function it_honours_the_requested_sort_mode_and_rejects_an_unknown_one(): void
    {
        $this->makeUserCard($this->owner->id);

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index', ['sort' => 'payment_due']))
            ->assertOk()
            ->assertJsonPath('sort_mode', 'payment_due');

        // Giá trị lạ (sửa tay URL) rơi về `manual`, KHÔNG ném lỗi — hỏng thứ tự
        // hiển thị thì tệ hơn là trắng trang.
        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index', ['sort' => 'xóa-hết-mọi-thứ']))
            ->assertOk()
            ->assertJsonPath('sort_mode', 'manual');
    }

    #[Test]
    public function previewing_another_sort_mode_does_not_change_the_saved_order(): void
    {
        $first = $this->makeUserCard($this->owner->id, ['sort_order' => 1]);
        $second = $this->makeUserCard($this->owner->id, ['sort_order' => 2]);

        $this->actingAs($this->owner)
            ->getJson(route('credit-cards.api.statements.index', ['sort' => 'payment_due']))
            ->assertOk();

        // Ba chế độ tự động chỉ để XEM TRƯỚC: `sort_order` của user giữ nguyên,
        // nếu không thì chỉ cần xem sai một lần là mất thứ tự đã sắp.
        $this->assertSame(1, (int) $first->refresh()->sort_order);
        $this->assertSame(2, (int) $second->refresh()->sort_order);
    }

    // =====================================================================
    // Menu module
    // =====================================================================

    #[Test]
    public function the_menu_places_statements_right_after_card_management(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $manage = strpos($html, 'href="'.route('credit-cards.manage').'"');
        $statements = strpos($html, 'href="'.route('credit-cards.statements').'"');
        $policies = strpos($html, 'href="'.route('credit-cards.policies').'"');

        $this->assertNotFalse($manage);
        $this->assertNotFalse($statements);
        $this->assertNotFalse($policies);

        // "Sao kê" nằm NGAY sau "Quản lý thẻ", trước "Chính sách hoàn tiền":
        // cả ba là việc làm với thẻ, nên nhóm lại thay vì rải khắp menu.
        $this->assertLessThan($statements, $manage, 'Sao kê phải nằm sau Quản lý thẻ.');
        $this->assertLessThan($policies, $statements, 'Sao kê phải nằm trước Chính sách hoàn tiền.');
    }

    #[Test]
    public function the_menu_marks_statements_as_active(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('credit-cards.statements'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<a(?=[^>]*aria-current="page")(?=[^>]*href="'.preg_quote(route('credit-cards.statements'), '/').'")[^>]*>/',
            $html,
            'Trang Sao kê phải được đánh active trong menu.',
        );
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * CHỮ NGƯỜI DÙNG THẬT SỰ THẤY trong HTML: bóc thẻ, giải thực thể rồi gộp
     * khoảng trắng.
     *
     * `&nbsp;` đổi về space thường để kỳ vọng viết được như người đọc — hành vi
     * "không đứt dòng" của nó do helper hiển thị tiền lo, không thuộc test này.
     */
    private function visibleTextOf(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Nội dung mọi thẻ `<script>` trong trang, nối lại.
     *
     * Test nào cần khẳng định "JavaScript KHÔNG làm gì đó" thì soi chỗ này, không
     * soi cả HTML: class CSS hay câu chữ tiếng Việt rất dễ chứa ký tự trùng với
     * biểu thức cần tìm và làm test xanh nhầm.
     */
    private function scriptOf(string $html): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches);

        return implode("\n", $matches[1] ?? []);
    }

    /**
     * JavaScript đã bỏ comment.
     *
     * Comment trong module giải thích *vì sao* không làm việc X, nên nó chứa đúng
     * những chữ mà test đang săn ("cộng/trừ", "sau khi lưu"). Đọc cả comment thì
     * test đỏ vì chính lời giải thích.
     */
    private function codeOf(string $script): string
    {
        $code = preg_replace('#/\*.*?\*/#s', ' ', $script) ?? $script;
        $code = preg_replace('#(^|\s)//[^\n]*#', ' ', $code) ?? $code;

        return $code;
    }

    /**
     * Ghi một dòng sao kê qua service (đúng đường đi của app).
     *
     * Mặc định ghi vào KỲ ĐÃ KẾT THÚC GẦN NHẤT — cũng là kỳ mà trang mở ra mặc
     * định — nên test không phải nhắc lại kỳ mỗi lần chỉ muốn "có sao kê để
     * hiển thị". Truyền `period_start` để chọn kỳ khác.
     */
    private function createStatement(UserCard $card, string $spend, string $reward, ?string $periodStart = null): CreditCardStatement
    {
        $periodStart ??= $this->completedPeriodStart($card);

        return app(CreditCardStatementService::class)->upsertForPeriodStart($card, $periodStart, [
            'actual_spend' => $spend,
            'actual_reward' => $reward,
        ]);
    }

    /** `period_start` của KỲ ĐÃ KẾT THÚC GẦN NHẤT — kỳ mặc định của trang. */
    private function completedPeriodStart(UserCard $card): string
    {
        return app(CreditCardStatementService::class)->defaultPeriodStart($card);
    }

    /** `period_start` của KỲ HIỆN TẠI. */
    private function currentPeriodStart(UserCard $card): string
    {
        [$start] = app(StatementPeriodService::class)->currentBoundaries($card, CarbonImmutable::now());

        return $start->toDateString();
    }
}
