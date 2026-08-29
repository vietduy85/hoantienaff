<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'username' => strtolower(trim($request->username)),
        ]);

        $request->validate(
            [
                'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9_]+$/', 'unique:'.User::class],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
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

        $user = User::create([
            'username' => $request->username,
            'name' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
