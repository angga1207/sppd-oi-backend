<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileApiKeyMiddleware
{
    /**
     * Validate mobile app API key from environment variable.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('x-api-key');
        $validApiKey = config('app.mobile_api_key');

        if (!$validApiKey || $apiKey !== $validApiKey) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Invalid or missing API key.',
            ], 401);
        }

        return $next($request);
    }
}
