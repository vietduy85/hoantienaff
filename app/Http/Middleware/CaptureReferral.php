<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureReferral
{
    public function handle(Request $request, Closure $next): Response
    {
        $ref = $request->query('ref');

        if (is_string($ref) && $ref !== '') {
            $username = strtolower(trim($ref));

            if (preg_match('/^[a-z0-9_]{1,30}$/', $username)) {
                $request->session()->put('referral_ref', $username);
            }
        }

        return $next($request);
    }
}