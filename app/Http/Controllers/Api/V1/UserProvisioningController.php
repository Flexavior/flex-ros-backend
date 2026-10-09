<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserProvisioningController extends Controller
{
    /** Roles Sr. Management may assign from default Staff / SSO JIT queue. */
    private const ASSIGNABLE_ROLE_CODES = [
        Role::SALES,
        Role::MARKETING,
        Role::CUSTOMER_SERVICE,
        Role::STAFF,
        Role::SENIOR_STAFF,
    ];

    public function index(Request $request)
    {
        $this->authorizeProvisioner($request);

        $staffRoleId = Role::where('code', Role::STAFF)->value('id');
        $onlyPending = $request->boolean('pending_only', true);

        $query = User::with(['role:id,code,name', 'team:id,name', 'supervisor:id,name', 'identities:id,user_id,provider'])
            ->orderByDesc('created_at');

        if ($onlyPending) {
            $query->where('role_id', $staffRoleId)
                ->where(function ($q) {
                    $q->whereHas('identities')
                        ->orWhereNull('team_id');
                });
        }

        return response()->json([
            'staff_role_id' => $staffRoleId,
            'assignable_roles' => Role::whereIn('code', self::ASSIGNABLE_ROLE_CODES)
                ->orderBy('level')
                ->get(['id', 'code', 'name']),
            'teams' => Team::orderBy('name')->get(['id', 'name']),
            'users' => $query->limit(100)->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'role' => $u->role,
                'team' => $u->team,
                'supervisor' => $u->supervisor,
                'sso_providers' => $u->identities->pluck('provider')->unique()->values(),
                'created_at' => $u->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function assignRole(Request $request, User $user)
    {
        $this->authorizeProvisioner($request);

        $data = $request->validate([
            'role_code' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLE_CODES)],
            'team_id' => 'nullable|exists:teams,id',
            'supervisor_id' => 'nullable|exists:users,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $roleId = Role::where('code', $data['role_code'])->value('id');
        abort_unless($roleId, 422, 'Unknown role.');

        abort_if($user->hasRole(Role::ADMIN) && !$request->user()->hasRole(Role::ADMIN), 403);

        $user->role_id = $roleId;
        if (array_key_exists('team_id', $data)) {
            $user->team_id = $data['team_id'];
        }
        if (array_key_exists('supervisor_id', $data)) {
            $user->supervisor_id = $data['supervisor_id'];
        }
        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];
        }
        $user->save();

        if ($user->team_id) {
            $user->team?->members()->syncWithoutDetaching([$user->id]);
        }

        return response()->json($user->load(['role:id,code,name', 'team:id,name', 'supervisor:id,name']));
    }

    protected function authorizeProvisioner(Request $request): void
    {
        abort_unless(
            $request->user()->hasAnyRole([Role::ADMIN, Role::SENIOR_MANAGEMENT, Role::CEO]),
            403,
            'User provisioning requires Senior Management or Admin.'
        );
    }
}
