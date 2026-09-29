<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Domain\Crm\DashboardService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboardService)
    {
    }

    public function metrics(Request $request)
    {
        return response()->json(
            $this->dashboardService->metrics($request->user())
        );
    }
}
