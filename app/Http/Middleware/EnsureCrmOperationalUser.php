<?php

namespace App\Http\Middleware;

use App\Domain\Documents\DocumentLibraryAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCrmOperationalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || !DocumentLibraryAccess::userMayAccessCrmModule($user)) {
            abort(403, 'System administrators use Admin Console and Settings — not operational CRM modules.');
        }

        return $next($request);
    }
}
