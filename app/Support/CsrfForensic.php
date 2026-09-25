<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * FORENSIC-INSTRUMENTATION-ONLY helper (Root Cause A + Root Cause B tracking).
 *
 * NEVER changes request behavior. NEVER logs raw tokens / session ids / cookies.
 * Never throws. Every event is ONE JSON line written to the 'csrf-forensic'
 * log channel (storage/logs/csrf-forensic.log).
 *
 * Fingerprints are SHA-256 truncated to 10 chars (e.g. 9d4e89a079).
 */
class CsrfForensic
{
    protected const MARKER = 'CSRF_FORENSIC';

    /**
     * SHA-256 fingerprint truncated to 10 hex chars.
     */
    public static function fp(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        try {
            return substr(hash('sha256', $v), 0, 10);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fingerprint of the request token: _token input first, then X-CSRF-TOKEN header.
     */
    public static function requestTokenFp(Request $request): ?string
    {
        $token = $request->input('_token');
        if (! is_string($token) || $token === '') {
            $token = $request->header('X-CSRF-TOKEN');
        }

        return self::fp(is_string($token) ? $token : null);
    }

    /**
     * Write one goal-line event to the csrf-forensic channel.
     */
    public static function event(string $ev, Request $request, array $extra = []): void
    {
        try {
            $hasSession = $request->hasSession();
            $session = $hasSession ? $request->session() : null;

            $sessionId = null;
            $sessionToken = null;
            if ($session !== null) {
                try {
                    $sessionId = $session->getId();
                } catch (\Throwable $e) {
                    $sessionId = null;
                }
                try {
                    $sessionToken = $session->get('_token');
                } catch (\Throwable $e) {
                    $sessionToken = null;
                }
            }

            $loginId = null;
            if ($session !== null) {
                try {
                    foreach ($session->all() as $k => $v) {
                        if (is_string($k) && str_starts_with($k, 'login_')) {
                            $loginId = is_numeric($v) ? (int) $v : null;
                            break;
                        }
                    }
                } catch (\Throwable $e) {
                    $loginId = null;
                }
            }

            $recaller = null;
            try {
                foreach ($request->cookies->keys() as $k) {
                    if (is_string($k) && str_starts_with($k, 'remember_')) {
                        $recaller = (string) $request->cookies->get($k);
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $recaller = null;
            }

            $routeName = null;
            try {
                $routeName = optional($request->route())->getName();
            } catch (\Throwable $e) {
                $routeName = null;
            }

            $payload = array_merge([
                'ev' => $ev,
                'ts' => now()->format('Y-m-d\TH:i:s.uP'),
                'tz' => (string) config('app.timezone', 'UTC'),
                'method' => (string) $request->method(),
                'uri' => (string) $request->path(),
                'route' => $routeName ?? 'n/a',
                'session_id_fp' => self::fp($sessionId),
                'session_token_fp' => self::fp($sessionToken),
                'page_token_fp' => self::fp($sessionToken),
                'request_token_fp' => self::requestTokenFp($request),
                'auth_state' => $loginId !== null ? 'login_web_present' : 'guest',
                'user_id' => $loginId,
                'recaller_present' => $recaller !== null,
                'recaller_fp' => self::fp($recaller),
                'ip_fp' => self::fp((string) $request->ip()),
                'ua' => mb_substr((string) $request->userAgent(), 0, 200),
                'referer' => mb_substr((string) $request->headers->get('referer'), 0, 300),
            ], $extra);

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                $json = 'encode-failed';
            }

            Log::channel('csrf-forensic')->info(self::MARKER.' '.$json);
        } catch (\Throwable $e) {
            // swallow: diagnostics must never affect request handling.
        }
    }
}