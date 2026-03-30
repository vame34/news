<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->get('admin_auth', false)) {
            return redirect('/admin/login');
        }

        return $next($request);
    }
}
