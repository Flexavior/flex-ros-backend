<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSalesCrmUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $roleCode = $request->user()?->role?->code;

        if (!in_array($roleCode, Role::SALES_CRM, true)) {
            abort(
                403,
                'Leads and Customers are not available for your role. Contact your supervisor if you need access.'
            );
        }

        return $next($request);
    }
}
