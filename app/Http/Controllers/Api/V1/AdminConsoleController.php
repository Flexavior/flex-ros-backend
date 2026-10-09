<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

class AdminConsoleController extends Controller
{
    public function overview(Request $request)
    {
        abort_unless($request->user()->hasRole(Role::ADMIN), 403);

        $staffRoleId = Role::where('code', Role::STAFF)->value('id');

        return response()->json([
            'users' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'awaiting_role_assignment' => User::where('role_id', $staffRoleId)
                    ->whereHas('identities')
                    ->count(),
            ],
            'auth' => [
                'sso_microsoft_enabled' => (bool) Setting::get('auth.sso.microsoft_enabled', false),
                'sso_google_enabled' => (bool) Setting::get('auth.sso.google_enabled', false),
            ],
            'realtime' => [
                'broadcast_driver' => config('broadcasting.default'),
                'reverb_configured' => filled(env('REVERB_APP_KEY')),
                'inbox_live_updates' => in_array(config('broadcasting.default'), ['reverb', 'pusher', 'log'], true),
            ],
            'crm' => [
                'stale_task_days' => (int) Setting::get('crm.stale_task_days', 5),
                'client_id_prefix' => Setting::get('crm.client_id_prefix', 'CUS'),
            ],
        ]);
    }
}
