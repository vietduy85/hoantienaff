<?php

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use App\Support\CsrfForensic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TEMPORARY controlled-test tool for the T2 V2 CSRF self-recovery proof.
 *
 * Rotates the CURRENT authenticated session (session id + CSRF token) so the
 * already-rendered dashboard tab keeps the OLD token: the next UI click gets a
 * real 419, then T2 V2 recovers via GET /csrf-token + a single retry.
 *
 * Constraints enforced here:
 *  - POST-only route (GET => 405 by routing).
 *  - Normal web CSRF validation still applies (route is NOT in the api/* whitelist).
 *  - Flag-gated: returns 404 unless config('app.t2_test_enabled') is true.
 *  - Only user_id = 5 (from the authenticated session, never from request input).
 *  - Never returns/logs raw session id, raw CSRF token, raw cookie or passwords.
 *    Response and forensic event carry SHA-256 fingerprints only.
 *
 * Cleanup: remove this controller, the route and the config key (Phase 8).
 */
class T2TestRotateSessionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! config('app.t2_test_enabled')) {
            abort(404);
        }

        if ((int) $request->user()->getAuthIdentifier() !== 5) {
            abort(403);
        }

        $session = $request->session();

        $sessionBeforeFp = CsrfForensic::fp($session->getId());
        $tokenBeforeFp = CsrfForensic::fp($session->token());

        // Laravel 12: session id migrate() + _token regenerateToken() in one call.
        $session->regenerate();

        $sessionAfterFp = CsrfForensic::fp($session->getId());
        $tokenAfterFp = CsrfForensic::fp($session->token());

        CsrfForensic::event('t2_test_rotate', $request, [
            'session_before_fp' => $sessionBeforeFp,
            'session_after_fp' => $sessionAfterFp,
            'token_before_fp' => $tokenBeforeFp,
            'token_after_fp' => $tokenAfterFp,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'T2 controlled test: session + CSRF token rotated.',
            'session_before_fp' => $sessionBeforeFp,
            'session_after_fp' => $sessionAfterFp,
            'token_before_fp' => $tokenBeforeFp,
            'token_after_fp' => $tokenAfterFp,
        ]);
    }
}