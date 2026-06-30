<?php

namespace App\Http\Controllers\Debug;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CookieDebugController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            $session->start();
        }

        return response()->json([
            'cookies_received' => $request->cookies->all(),
            'headers_received' => [
                'cookie'           => $request->header('cookie'),
                'x-forwarded-proto' => $request->header('x-forwarded-proto'),
                'x-forwarded-for'  => $request->header('x-forwarded-for'),
                'x-forwarded-host' => $request->header('x-forwarded-host'),
                'cf-visitor'       => $request->header('cf-visitor'),
                'cf-ray'           => $request->header('cf-ray'),
                'host'             => $request->header('host'),
                'user-agent'       => $request->header('user-agent'),
                'x-xsrf-token'     => $request->header('x-xsrf-token'),
                'x-csrf-token'     => $request->header('x-csrf-token'),
            ],
            'request' => [
                'secure'   => $request->secure(),
                'scheme'   => $request->getScheme(),
                'method'   => $request->method(),
                'url'      => $request->fullUrl(),
                'root'     => $request->root(),
                'ip'       => $request->ip(),
            ],
            'session' => [
                'id'          => $session->getId(),
                'exists'      => $session->exists('_token'),
                'token'       => $session->token(),
                'previous'    => $session->previousUrl(),
            ],
            'csrf_token' => csrf_token(),
            'session_config' => [
                'cookie'    => config('session.cookie'),
                'domain'    => config('session.domain'),
                'path'      => config('session.path'),
                'secure'    => config('session.secure'),
                'http_only' => config('session.http_only'),
                'same_site' => config('session.same_site'),
                'driver'    => config('session.driver'),
                'lifetime'  => config('session.lifetime'),
                'expire_on_close' => config('session.expire_on_close'),
            ],
            'php_session' => [
                'id'     => session_id(),
                'name'   => session_name(),
                'status' => session_status(),
                'use_cookies' => ini_get('session.use_cookies'),
                'use_only_cookies' => ini_get('session.use_only_cookies'),
                'cookie_params' => session_get_cookie_params(),
            ],
        ]);
    }

    public function setCookie(Request $request): JsonResponse
    {
        $session = $request->session();
        if (!$session->isStarted()) {
            $session->start();
        }

        $session->put('hello', 'world');

        return response()->json([
            'session' => [
                'id'    => $session->getId(),
                'hello' => $session->get('hello'),
                'token' => $session->token(),
            ],
            'csrf_token' => csrf_token(),
            'cookie_config' => [
                'cookie'    => config('session.cookie'),
                'domain'    => config('session.domain'),
                'path'      => config('session.path'),
                'secure'    => config('session.secure'),
                'http_only' => config('session.http_only'),
                'same_site' => config('session.same_site'),
            ],
        ]);
    }
}
