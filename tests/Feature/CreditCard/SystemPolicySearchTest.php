<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\Bank;
use App\Models\CreditCard\PolicyTemplate;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * "Chính sách hoàn tiền hệ thống" — tìm kiếm trên trang danh sách.
 *
 * Server-side search (query builder) trên toàn bộ template hệ thống, lọc theo:
 * `name`, `description`, `applicable_product_match` và ngân hàng liên kết
 * (`applicable_bank_id` → `credit_card_banks`). Từ khoá giữ trên URL `?q=`.
 */
class SystemPolicySearchTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $member;

    private User $viewer;

    private User $manager;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        $admin = Role::create(['name' => 'Admin']);
        $admin->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);

        $this->member = User::factory()->create();
        $this->viewer = User::factory()->create()->givePermissionTo('credit-cards.view');
        $this->manager = $this->makeAdmin();
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return $admin;
    }

    private function makeSystemTemplate(array $attributes = []): PolicyTemplate
    {
        return PolicyTemplate::create(array_merge([
            'scope' => PolicyTemplate::SCOPE_SYSTEM,
            'owner_user_id' => PolicyTemplate::SYSTEM_OWNER_ID,
            'name' => 'Chính sách hệ thống '.uniqid(),
            'slug' => 'chinh-sach-he-thong-'.uniqid(),
            'is_builtin' => true,
            'is_active' => true,
        ], $attributes));
    }

    private function makeBank(string $name, string $shortName): void
    {
        Bank::create([
            'name' => $name,
            'short_name' => $shortName,
            'slug' => Str::slug($shortName),
            'is_active' => true,
        ]);
    }

    // =====================================================================
    // Danh sách mặc định (không từ khoá) không đổi
    // =====================================================================

    #[Test]
    public function index_without_keyword_returns_full_list_in_default_order(): void
    {
        $this->makeSystemTemplate(['name' => 'Shinhan Supreme Visa', 'sort_order' => 10]);
        $this->makeSystemTemplate(['name' => 'Cashback phổ thông', 'sort_order' => 1]);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index'))
            ->assertOk()
            ->assertSee('Shinhan Supreme Visa')
            ->assertSee('Cashback phổ thông')
            ->assertSee('+ Tạo chính sách hệ thống')
            ->assertSee('data-testid="policy-search-input"', false);
    }

    // =====================================================================
    // Tìm kiếm theo từng trường
    // =====================================================================

    #[Test]
    public function search_by_template_name(): void
    {
        $this->makeSystemTemplate(['name' => 'Shinhan Supreme Visa']);
        $this->makeSystemTemplate(['name' => 'Cashback phổ thông']);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'Shinhan']))
            ->assertOk()
            ->assertSee('Shinhan Supreme Visa')
            ->assertDontSee('Cashback phổ thông');
    }

    #[Test]
    public function search_by_description(): void
    {
        $this->makeSystemTemplate([
            'name' => 'Template A',
            'description' => 'Chỉ hoàn tiền cho chi tiêu tại siêu thị',
        ]);
        $this->makeSystemTemplate(['name' => 'Template B', 'description' => 'Không liên quan gì']);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'siêu thị']))
            ->assertOk()
            ->assertSee('Template A')
            ->assertDontSee('Template B');
    }

    #[Test]
    public function search_by_product_match(): void
    {
        $this->makeSystemTemplate([
            'name' => 'VIB Max Pro',
            'applicable_product_match' => 'max card visa',
        ]);
        $this->makeSystemTemplate(['name' => 'Cashback phổ thông']);

        // Từ khoá nằm ở `applicable_product_match`, không có trong tên.
        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'max card visa']))
            ->assertOk()
            ->assertSee('VIB Max Pro')
            ->assertDontSee('Cashback phổ thông');
    }

    #[Test]
    public function search_by_applicable_bank(): void
    {
        $this->makeBank('Quốc tế', 'VIB');
        $vib = Bank::query()->where('short_name', 'VIB')->firstOrFail();
        $this->makeSystemTemplate([
            'name' => 'Template riêng A',
            'applicable_bank_id' => $vib->id,
        ]);
        $this->makeSystemTemplate(['name' => 'Cashback phổ thông']);

        // Khớp qua short_name của ngân hàng liên kết.
        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'VIB']))
            ->assertOk()
            ->assertSee('Template riêng A')
            ->assertDontSee('Cashback phổ thông');
    }

    #[Test]
    public function search_by_bank_name_with_diacritics(): void
    {
        $this->makeBank('Quốc tế', 'VIB');
        $vib = Bank::query()->where('short_name', 'VIB')->firstOrFail();
        $this->makeSystemTemplate([
            'name' => 'Template riêng B',
            'applicable_bank_id' => $vib->id,
        ]);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'Quốc tế']))
            ->assertOk()
            ->assertSee('Template riêng B');
    }

    // =====================================================================
    // Không phân biệt chữ hoa / thường
    // =====================================================================

    #[Test]
    public function search_is_case_insensitive(): void
    {
        $this->makeSystemTemplate(['name' => 'VIB One Card Gold']);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'vib']))
            ->assertOk()
            ->assertSee('VIB One Card Gold');

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'GOLD']))
            ->assertOk()
            ->assertSee('VIB One Card Gold');
    }

    // =====================================================================
    // Không khớp / xoá từ khoá
    // =====================================================================

    #[Test]
    public function keyword_without_match_shows_search_empty_state_and_keeps_keyword_in_url(): void
    {
        $this->makeSystemTemplate(['name' => 'Shinhan Supreme Visa']);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'khong-ton-tai']))
            ->assertOk()
            ->assertSee('Không tìm thấy chính sách hoàn tiền phù hợp.')
            ->assertSee('data-testid="policy-search-empty"', false)
            ->assertSee('value="khong-ton-tai"', false)
            ->assertDontSee('Shinhan Supreme Visa');
    }

    #[Test]
    public function clearing_keyword_returns_full_list(): void
    {
        $this->makeSystemTemplate(['name' => 'Shinhan Supreme Visa']);
        $this->makeSystemTemplate(['name' => 'Cashback phổ thông']);

        // Khi có từ khoá: nút xoá hiện trên ô tìm kiếm.
        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'Shinhan']))
            ->assertOk()
            ->assertSee('data-testid="policy-search-clear"', false);

        // Không từ khoá (hoặc q rỗng): danh sách đầy đủ, không còn nút xoá.
        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index'))
            ->assertOk()
            ->assertSee('Shinhan Supreme Visa')
            ->assertSee('Cashback phổ thông')
            ->assertDontSee('data-testid="policy-search-clear"', false);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => '']))
            ->assertOk()
            ->assertSee('Shinhan Supreme Visa')
            ->assertSee('Cashback phổ thông');
    }

    // =====================================================================
    // Toàn bộ danh sách (không phân trang, không bỏ sót) + thao tác mỗi card
    // =====================================================================

    #[Test]
    public function search_matches_full_list_without_dropping_results(): void
    {
        $this->makeSystemTemplate(['name' => 'VIB One Card Gold', 'sort_order' => 1]);
        $this->makeSystemTemplate(['name' => 'VIB One Card Platinum', 'sort_order' => 2]);
        $this->makeSystemTemplate(['name' => 'Shinhan Supreme Visa', 'sort_order' => 3]);

        $this->actingAs($this->viewer)
            ->get(route('admin.credit-card-policies.index', ['q' => 'VIB']))
            ->assertOk()
            ->assertSee('VIB One Card Gold')
            ->assertSee('VIB One Card Platinum')
            ->assertDontSee('Shinhan Supreme Visa');
    }

    #[Test]
    public function search_result_cards_still_render_action_buttons(): void
    {
        $template = $this->makeSystemTemplate(['name' => 'Shinhan Supreme Visa']);

        $this->actingAs($this->manager)
            ->get(route('admin.credit-card-policies.index', ['q' => 'Shinhan']))
            ->assertOk()
            ->assertSee(route('admin.credit-card-policies.show', $template->id))
            ->assertSee(route('admin.credit-card-policies.edit', $template->id))
            ->assertSee(route('admin.credit-card.system-policies.clone', $template->id));
    }

    // =====================================================================
    // Phân quyền không bị nới lỏng bởi tham số tìm kiếm
    // =====================================================================

    #[Test]
    public function guest_is_redirected_and_member_is_forbidden_even_with_search_param(): void
    {
        $this->makeSystemTemplate(['name' => 'Shinhan Supreme Visa']);

        $this->get(route('admin.credit-card-policies.index', ['q' => 'Shinhan']))
            ->assertRedirect(route('login'));

        $this->actingAs($this->member)
            ->get(route('admin.credit-card-policies.index', ['q' => 'Shinhan']))
            ->assertForbidden();
    }
}
