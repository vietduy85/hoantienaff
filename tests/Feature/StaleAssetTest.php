<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleAssetTest extends TestCase
{
    use RefreshDatabase;

    private function currentFile(string $entry): string
    {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);

        return $manifest[$entry]['file'];
    }

    public function test_html_pages_are_not_stored_by_browser(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->get('/login')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_old_build_css_and_js_redirect_to_current_files(): void
    {
        $this->get('/build/assets/app-B0wy316P.css')
            ->assertRedirect('/build/'.$this->currentFile('resources/css/app.css'))
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertCookieMissing(config('session.cookie'));

        $this->get('/build/assets/app-YANisdxd.js')
            ->assertRedirect('/build/'.$this->currentFile('resources/js/app.js'));

        // Entry có dấu "-" trong tên, hash có "-"/"_".
        $this->get('/build/assets/in-app-browser-Ab_cd-12.js')
            ->assertRedirect('/build/'.$this->currentFile('resources/js/in-app-browser.js'));
    }

    public function test_unknown_assets_are_404(): void
    {
        $this->get('/build/assets/khong-co-gi12.css')->assertNotFound();
        $this->get('/build/assets/app-B0wy316P.css.map')->assertNotFound();
        $this->get('/build/assets/app-B0wy316P.png')->assertNotFound();
    }
}
