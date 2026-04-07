<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestIdMiddleware
{
    /**
     * Generate and attach a unique request ID for tracking and debugging.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate unique request ID
        $requestId = Str::uuid()->toString();

        // Store in request for use in logging/debugging
        $request->attributes->set('request_id', $requestId);

        // Process request
        $response = $next($request);

        // Attach request ID to response headers
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
