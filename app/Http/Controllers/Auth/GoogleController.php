<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Exception $e) {
            return redirect()->route('login')
                ->with('error', 'Đăng nhập Google thất bại hoặc đã bị hủy.');
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            $user->update([
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
            ]);

            session()->forget('referral_ref');

            Auth::login($user, true);

            if ($user->username) {
                return redirect()->intended(route('dashboard', absolute: false));
            }

            return redirect()->route('complete-profile.create');
        }

        $user = User::create([
            'username' => null,
            'name' => $googleUser->getName(),
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'avatar' => $googleUser->getAvatar(),
            'password' => Hash::make(Str::random(32)),
        ]);

        $this->attachReferralForNewUser($user);

        Auth::login($user, true);

        return redirect()->route('complete-profile.create');
    }

    private function attachReferralForNewUser(User $user): void
    {
        $refUsername = (string) session()->pull('referral_ref');

        if ($refUsername === '') {
            return;
        }

        try {
            app(ReferralService::class)->attachReferrer($user, $refUsername);
        } catch (\Throwable $e) {
            Log::warning('Referral attachment failed', [
                'user_id' => $user->id,
                'ref_username' => $refUsername,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
