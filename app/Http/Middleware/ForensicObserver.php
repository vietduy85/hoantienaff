<?php

namespace App\Http\Middleware;

use App\Support\CsrfForensic;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * FORENSIC-INSTRUMENTATION-ONLY observer.
 *
 * Runs on the dashboard route (records the rendered page token + correlation id)
 * and on link-requests.store (records the request/session/log state and the
 * response status). If the controller throws (e.g. validation 422) the same
 * event is logged with the exception status and the exception is re-thrown
 * unmodified. NEVER changes the response and never swallows exceptions.
 */
class ForensicObserver
{
    public function handle(Request $request, Closure $next)
    {
        $route = $request->route();
        $routeName = $route ? $route->getName() : null;

        if ($request->isMethod('GET') && $routeName === 'dashboard') {
            $pageInstanceId = 'PAGE-'.bin2hex(random_bytes(8));
            $request->attributes->set('forensic.page_instance_id', $pageInstanceId);
            View::share('page_instance_id', $pageInstanceId);

            // Same as Blade's `{{ csrf_token() }}`: guarantees the _token that the
            // rendered page meta/JS will carry exists (default behaviour of the view).
            if ($request->hasSession()) {
                $request->session()->token();
            }

            CsrfForensic::event('dashboard_render', $request, [
                'page_instance_id' => $pageInstanceId,
            ]);
        }

        if ($request->isMethod('POST') && $routeName === 'link-requests.store') {
            try {
                $response = $next($request);

                CsrfForensic::event('link_request_post', $request, [
                    'page_instance_id' => (string) $request->header('X-Forensic-Page-Id', ''),
                    't2_flow_id' => (string) $request->header('X-Forensic-T2-Flow-Id', ''),
                    'response_status' => $response->getStatusCode(),
                ]);

                return $response;
            } catch (\Throwable $e) {
                CsrfForensic::event('link_request_post', $request, [
                    'page_instance_id' => (string) $request->header('X-Forensic-Page-Id', ''),
                    't2_flow_id' => (string) $request->header('X-Forensic-T2-Flow-Id', ''),
                    'response_status' => method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500,
                    'exception' => get_class($e),
                ]);

                throw $e;
            }
        }

        return $next($request);
    }
}