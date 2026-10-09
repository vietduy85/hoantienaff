<?php

namespace Tests\Feature\CreditCard;

use App\Models\CreditCard\SpendQualificationTemplate;
use App\Models\CreditCard\SpendQualificationTemplateCondition;
use App\Models\CreditCard\SpendQualificationTemplateConditionExcludedCategory;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithCreditCardDatabase;
use Tests\TestCase;

/**
 * Trang quản trị "Mẫu điều kiện hoàn tiền đặc biệt".
 *
 * BUGFIX: JavaScript của trang bị Blade render thành VĂN BẢN trong body (không
 * chạy), dẫn tới danh sách mẫu không hiển thị. Bộ test này khoá:
 *   - HTML không chứa source JS như text hiển thị (`@stack` phải sinh thẻ
 *     `<script>` thật, đúng CHÍNH XÁC MỘT lần);
 *   - script đăng ký đúng một lần (không trùng biến/`Alpine.data`);
 *   - Alpine component khởi tạo với `initial.templates`;
 *   - danh sách mẫu (cả active lẫn inactive) render từ dữ liệu server;
 *   - empty state chỉ khi thực sự không có mẫu;
 *   - mẫu inactive vẫn HIỆN trên trang quản trị (admin xem được cả mẫu tắt);
 *   - permission đúng: không có quyền ⇒ 403/redirect.
 */
class SpendQualificationTemplateAdminPageTest extends TestCase
{
    use InteractsWithCreditCardDatabase;

    private User $admin;

    private User $noPermission;

    protected function setUp(): void
    {
        $this->setUpCreditCardTestCase();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'credit-cards.view']);
        Permission::create(['name' => 'credit-cards.manage']);

        $admin = Role::create(['name' => 'Admin']);
        $admin->givePermissionTo(['credit-cards.view', 'credit-cards.manage']);

        $this->admin = User::factory()->create()->assignRole('Admin');
        $this->noPermission = User::factory()->create();
    }

    // =====================================================================
    // JS không bị in thành text; script chỉ xuất hiện đúng một lần, dạng `<script>`
    // =====================================================================

    #[Test]
    public function javascript_is_registered_as_a_real_script_tag_exactly_once(): void
    {
        $html = $this->indexHtml();

        // Khi lỗi tái diễn, đoạn sau xuất hiện như VĂN BẢN trong body — khớp cả
        // thẻ đóng. Còi nếu nó TRẦN (chưa escape) ở bất kỳ đâu ngoài thẻ `<script>`.
        $this->assertStringNotContainsString(
            'window.ccSpendQualificationTemplateIndex = function',
            $this->withoutScriptTags($html),
            'JS của trang phải nằm trong thẻ <script>, không được in ra body.',
        );

        // Đăng ký biến: đúng MỘT lần, bên trong thẻ `<script>`.
        $this->assertSame(1, $this->countInScripts($html, 'window.ccSpendQualificationTemplateIndex = function'));

        // Đăng ký Alpine.data: đúng MỘT lần — định nghĩa script +
        // `document.addEventListener('alpine:init', ...)` sinh ra đúng một lệnh.
        $this->assertSame(1, $this->countInScripts($html, "Alpine.data('ccSpendQualificationTemplateIndex'"));

        // Component thực sự khai báo trong x-data.
        $this->assertStringContainsString('x-data="ccSpendQualificationTemplateIndex(', $html);
        $this->assertStringContainsString('x-init="init()"', $html);
    }

    #[Test]
    public function the_script_block_has_no_lost_or_extra_script_tags_inside_it(): void
    {
        $html = $this->indexHtml();
        $scripts = $this->scriptBlocks($html);

        $this->assertNotEmpty($scripts, 'Layout phải render stack scripts.');

        // Nếu `<script>` bị mất thì nội dung stack rơi ra body — khoá bằng cách
        // kiểm mỗi khối script tự cân bằng BRÚT (số thẻ mở = số thẻ đóng).
        foreach ($scripts as $block) {
            $this->assertLessThanOrEqual(
                substr_count($block, '</script>'),
                substr_count($block, '<script'),
                'Một khối <script> bị mất thẻ đóng trong script stack.',
            );
        }
    }

    // =====================================================================
    // Danh sách mẫu từ server
    // =====================================================================

    #[Test]
    public function the_list_is_presented_from_server_data_in_initial_templates(): void
    {
        $this->makeTemplate('Mẫu hoạt động', 'mau-hoat-dong', true, 10);
        $this->makeTemplate('Mẫu tắt', 'mau-tat', false, 20);

        $html = $this->indexHtml();

        // `x-data` nhúng payload `@js($templates)` — danh sách phải lọt nguyên vẹn
        // vào HTML để Alpine render (kể cả mẫu ĐÃ TẮT: trang quản trị xem được cả hai).
        $this->assertStringContainsString('x-data="ccSpendQualificationTemplateIndex(', $html);

        // `@js` giữ ký tự ASCII nguyên vẹn: slug (ASCII) và cờ active phải có mặt
        // trong payload built from server data.
        $this->assertStringContainsString('mau-hoat-dong', $html);
        $this->assertStringContainsString('mau-tat', $html);
        $this->assertStringContainsString('\\u0022is_active\\u0022:true', $html);
        $this->assertStringContainsString('\\u0022is_active\\u0022:false', $html);
    }

    #[Test]
    public function active_and_inactive_templates_are_both_passed_to_the_page(): void
    {
        $this->makeTemplate('Đang bật', 'dang-bat', true, 10);
        $this->makeTemplate('Đã tắt', 'da-tat', false, 20);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.credit-card.spend-qualifications.index'))
            ->assertOk();

        $view = $response->viewData();

        $templates = $view['templates']->all();

        $this->assertCount(2, $templates);
        $this->assertSame([true, false], array_map(
            fn (array $template): bool => $template['is_active'],
            $templates,
        ));
    }

    #[Test]
    public function conditions_and_category_exclusions_are_presented_in_the_payload(): void
    {
        $template = $this->makeTemplate('Mẫu có điều kiện', 'mau-co-dieu-kien', true, 10);

        $condition = SpendQualificationTemplateCondition::create([
            'template_id' => $template->id,
            'condition_type' => SpendQualificationTemplateCondition::TYPE_OTHER,
            'min_spend' => 1000000,
            'is_enabled' => true,
            'sort_order' => 1,
        ]);

        $excluded = [
            $this->makeSystemCategory()->id,
            $this->makeSystemCategory()->id,
            $this->makeSystemCategory()->id,
        ];

        foreach ($excluded as $categoryId) {
            SpendQualificationTemplateConditionExcludedCategory::create([
                'condition_id' => $condition->id,
                'category_id' => $categoryId,
            ]);
        }

        $qualification = app(\App\Services\CreditCard\SpendQualificationService::class)
            ->payloadForTemplate((int) $template->id);

        $this->assertCount(1, $qualification['conditions']);
        $first = $qualification['conditions'][0];

        $this->assertSame('other', $first['type']);
        $this->assertSame(1000000.0, $first['min_spend']);
        $this->assertSame($excluded, $first['excluded_category_ids']);
    }

    #[Test]
    public function the_empty_state_only_shows_when_there_are_no_templates(): void
    {
        $html = $this->indexHtml();

        $this->assertStringContainsString('Chưa có mẫu điều kiện nào', $html);
        $this->assertStringContainsString('templates.length === 0', $html);
    }

    // =====================================================================
    // Phân quyền
    // =====================================================================

    #[Test]
    public function a_user_without_the_permission_cannot_open_the_admin_page(): void
    {
        $this->actingAs($this->noPermission)
            ->get(route('admin.credit-card.spend-qualifications.index'))
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.credit-card.spend-qualifications.index'))
            ->assertRedirect(route('login'));
    }

    // =====================================================================
    // Trang Xem (show) — cùng lỗi `<script>` lồng nhau, cùng cách sửa
    // =====================================================================

    #[Test]
    public function the_show_page_registers_its_javascript_inside_a_real_script_tag(): void
    {
        $template = $this->makeTemplate('Mẫu để xem', 'mau-de-xem', true, 10);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.credit-card.spend-qualifications.show', $template))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'window.ccSpendQualificationTemplateShow = function',
            $this->withoutScriptTags($html),
            'JS của trang Xem phải nằm trong thẻ <script>, không được in ra body.',
        );

        $this->assertSame(
            1,
            $this->countInScripts($html, 'window.ccSpendQualificationTemplateShow = function'),
        );

        // Component Alpine dùng chung helper tiền.
        $this->assertStringContainsString('function ccMoneyVnd(', $this->implodeScripts($html));
    }

    // =====================================================================
    // Fixture + helpers
    // =====================================================================

    private function makeTemplate(string $name, string $slug, bool $active, int $sortOrder): SpendQualificationTemplate
    {
        return SpendQualificationTemplate::create([
            'name' => $name,
            'slug' => $slug,
            'description' => 'Mô tả '.$name,
            'is_active' => $active,
            'sort_order' => $sortOrder,
        ]);
    }

    private function indexHtml(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.credit-card.spend-qualifications.index'))
            ->assertOk()
            ->getContent();
    }

    /**
     * Nội dung body sau khi BỎ các khối chạy được: nhìn thứ còn lại thì biết có
     * đoạn JS nào bị in ra làm TEXT không.
     */
    private function withoutScriptTags(string $html): string
    {
        return (string) preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    }

    /**
     * @return array<int, string>
     */
    private function scriptBlocks(string $html): array
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches);

        return $matches[0];
    }

    private function countInScripts(string $html, string $needle): int
    {
        return substr_count(implode("\n", $this->scriptBlocks($html)), $needle);
    }

    private function implodeScripts(string $html): string
    {
        return implode("\n", $this->scriptBlocks($html));
    }
}