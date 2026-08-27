<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckUsernameController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = Auth::user();
        if ($user && $user->username) {
            return response()->json([
                'available' => false,
                'message' => 'Username đã được thiết lập và không thể thay đổi.',
            ]);
        }

        $username = strtolower(trim($request->query('username', '')));

        if (!preg_match('/^[a-z0-9_-]{3,30}$/', $username)) {
            return response()->json([
                'available' => false,
                'message' => 'Username không hợp lệ.',
            ]);
        }

        if (in_array($username, config('usernames.reserved', []))) {
            return response()->json([
                'available' => false,
                'message' => 'Username này không được phép sử dụng.',
            ]);
        }

        $exists = User::where('username', $username)->exists();

        return response()->json([
            'available' => !$exists,
            'message' => $exists ? 'Username đã tồn tại.' : 'Username khả dụng.',
        ]);
    }
}
