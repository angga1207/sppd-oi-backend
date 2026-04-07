<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileApiKeyMiddleware
{
    private const API_KEY = 'app-@ngg4Gant3nG-SpPd-OI';

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('x-api-key');

        if ($apiKey !== self::API_KEY) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Invalid API key.',
            ], 401);
        }

        return $next($request);
    }
}
