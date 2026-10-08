<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CaptureRequestId
{
    /**
     * Capture or generate an incoming request correlation ID.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID');
        if (empty($requestId) || ! is_string($requestId)) {
            $requestId = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
