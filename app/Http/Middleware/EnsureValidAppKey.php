<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidAppKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configuredKey = (string) config('app.client_private_key', '');
        $headerName = (string) config('app.client_private_key_header', 'X-App-Private-Key');
        $providedKey = (string) $request->header($headerName, '');

        if ($configuredKey === '') {
            return response()->json([
                'message' => 'Application private key is not configured.',
            ], 503);
        }

        if (! hash_equals($configuredKey, $providedKey)) {
            return response()->json([
                'message' => 'Unauthorized application key.',
            ], 401);
        }

        return $next($request);
    }
}
