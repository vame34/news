<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InternalTokenMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) env('INTERNAL_API_TOKEN', 'dev-internal-token');
        $token = (string) $request->header('X-Internal-Token', '');

        if ($token !== $expected) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
