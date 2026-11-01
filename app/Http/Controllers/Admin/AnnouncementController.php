<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Rules\HttpUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Quản lý thông báo ngắn trên trang chủ hoàn tiền. */
class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::query()->orderBy('sort_order')->orderByDesc('id')->get(),
            'visibleIds' => Announcement::forHome()->pluck('id')->all(),
        ]);
    }

    public function create(): View
    {
        return view('admin.announcements.create', [
            'announcement' => new Announcement([
                'icon' => '📢',
                'color' => 'amber',
                'link_text' => Announcement::DEFAULT_LINK_TEXT,
                'open_new_tab' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Announcement::create($this->validatePayload($request));

        return redirect()->route('admin.announcements.index')
            ->with('success', __('Đã thêm thông báo.'));
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.edit', ['announcement' => $announcement]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validatePayload($request));

        return redirect()->route('admin.announcements.index')
            ->with('success', __('Đã cập nhật thông báo.'));
    }

    public function toggle(Announcement $announcement): RedirectResponse
    {
        $announcement->update(['is_active' => ! $announcement->is_active]);

        return redirect()->back()->with('success', $announcement->is_active
            ? __('Đã bật hiển thị thông báo.')
            : __('Đã tắt hiển thị thông báo.'));
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return redirect()->route('admin.announcements.index')
            ->with('success', __('Đã xoá thông báo.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:500'],
            'link_text' => ['nullable', 'string', 'max:60'],
            'url' => ['nullable', 'string', 'max:2048', new HttpUrl],
            'icon' => ['nullable', 'string', 'max:16'],
            'color' => ['required', Rule::in(array_keys(Announcement::COLORS))],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'sort_order' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ], [], [
            'content' => 'nội dung',
            'link_text' => 'chữ link',
            'url' => 'URL',
            'end_at' => 'kết thúc',
            'start_at' => 'bắt đầu',
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['open_new_tab'] = $request->boolean('open_new_tab');
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        return $validated;
    }
}
