<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CompleteProfileController extends Controller
{
    public function create(): View|RedirectResponse
    {
        $user = Auth::user();

        if ($user->username) {
            return redirect()->intended(route('dashboard'))
                ->with('info', 'Bạn đã hoàn tất cập nhật thông tin tài khoản. Username không thể thay đổi.');
        }

        $suggestion = null;

        if ($user->email) {
            $base = strtolower(explode('@', $user->email)[0]);
            $base = preg_replace('/[^a-z0-9_]/', '', $base);
            if (strlen($base) < 3) {
                $base = 'user' . $base;
            }
            $suggestion = $base;
            $counter = 2;
            while (User::where('username', $suggestion)->exists()) {
                $suggestion = $base . $counter;
                $counter++;
            }
        }

        return view('auth.complete-profile', [
            'user' => $user,
            'suggestion' => $suggestion,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if ($user->username) {
            return redirect()->intended(route('dashboard'))
                ->with('info', 'Bạn đã hoàn tất cập nhật thông tin tài khoản. Username không thể thay đổi.');
        }

        $request->merge([
            'username' => strtolower(trim($request->username)),
        ]);

        $request->validate(
            [
                'username' => [
                    'required',
                    'string',
                    'min:3',
                    'max:30',
                    'regex:/^[a-z0-9_]+$/',
                    Rule::unique(User::class)->ignore($user->id),
                ],
                'name' => ['required', 'string', 'max:255'],
            ],
            [
                'username.regex' => 'Username chỉ được chứa chữ cái, số và dấu gạch dưới (_).',
            ]
        );

        if (in_array($request->username, config('usernames.reserved', []))) {
            throw ValidationException::withMessages([
                'username' => __('Username này không được phép sử dụng.'),
            ]);
        }

        $user->update([
            'username' => $request->username,
            'name' => trim($request->name),
        ]);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
