<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLinkGeneratorViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_renders_complete_alpine_component(): void
    {
        $html = view('dashboard.partials.link-generator')->render();

        // A. HTML render thành công
        $this->assertNotEmpty($html);

        // F. Parse DOM thành công
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html);
        libxml_use_internal_errors($previous);
        $this->assertTrue($loaded);

        $div = null;
        foreach ($dom->getElementsByTagName('div') as $node) {
            if ($node->hasAttribute('x-data')) {
                $div = $node;
                break;
            }
        }

        $this->assertNotNull($div, 'Phải tồn tại phần tử có x-data');

        // B + C. x-data phải chứa đầy đủ T2 v2 logic (single-flight + /csrf-token)
        $xdata = $div->getAttribute('x-data');
        $this->assertStringContainsString('ensureFreshCsrf', $xdata);
        $this->assertStringContainsString('window.__csrfPromise', $xdata, 'Single-flight promise phải được dùng');
        $this->assertMatchesRegularExpression('#/csrf-token#', $xdata, 'Phải fetch GET /csrf-token (không reload /dashboard)');
        $this->assertStringContainsString('bindResumeProbe', $xdata, 'Probe tab resume phải tồn tại');
        $this->assertStringContainsString('async post(retry = true)', $xdata);
        $this->assertStringNotContainsString('window.location.pathname + window.location.search', $xdata, 'T2 cũ refetch GET /dashboard phải bị loại bỏ');

        // attribute x-data phải đóng đúng vị trí (không bị cắt tại name=)
        $this->assertStringEndsWith('}', trim($xdata), 'x-data attribute bị cắt sớm');

        // G. Class của component phải giữ nguyên
        $this->assertStringContainsString('bg-white', $div->getAttribute('class'));

        // D + E. Không được lộ JS raw ra text content của component
        $text = $div->textContent;
        $this->assertStringNotContainsString('window.location.href', $text, 'JS bị lộ ra text node');
        $this->assertStringNotContainsString('response.json', $text, 'JS bị lộ ra text node');
        $this->assertStringNotContainsString('<meta name=', $text, 'meta tag bị render thành text node');
        $this->assertStringNotContainsString('startPolling', $text, 'Method trong x-data bị lộ ra text node');
    }

    public function test_get_dashboard_renders_fixed_component_no_raw_js(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('x-data="', false);
        $response->assertSee('ensureFreshCsrf');
        $response->assertSee('bindResumeProbe');
        $response->assertSee('<meta name="csrf-token" content="', false);
        $response->assertDontSee('content="([^"]+)"', false, 'Regex cũ với quote thô không được xuất hiện');
    }
}
