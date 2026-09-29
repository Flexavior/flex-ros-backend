<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInboxAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()?->isInboxAgent()) {
            abort(403, 'Your role does not have inbox access.');
        }

        return $next($request);
    }
}
