<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\SsoService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AuthController extends Controller
{
    public function ssoConfig(SsoService $sso)
    {
        return response()->json($sso->config());
    }

    public function ssoRedirect(string $provider, SsoService $sso)
    {
        try {
            return response()->json(['url' => $sso->redirectUrl($provider)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function ssoCallback(Request $request, string $provider, SsoService $sso)
    {
        $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        $frontend = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');
        try {
            $exchangeCode = $sso->handleCallback($provider, (string) $request->query('code'), (string) $request->query('state'));
        } catch (RuntimeException $e) {
            return redirect($frontend.'/auth/sso/callback?error='.urlencode($e->getMessage()));
        }

        return redirect($frontend.'/auth/sso/callback?code='.urlencode($exchangeCode).'&provider='.urlencode($provider));
    }

    public function ssoExchange(Request $request, SsoService $sso)
    {
        $data = $request->validate([
            'code' => 'required|string',
        ]);

        try {
            return response()->json($sso->exchange($data['code']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('role')->where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Account is disabled.'],
            ]);
        }

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->present($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json($this->present($request->user()->load('role', 'supervisor', 'team')));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    protected function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role?->only(['code', 'name', 'level']),
            'supervisor' => $user->supervisor?->only(['id', 'name']),
            'team' => $user->team?->only(['id', 'name']),
            'is_active' => $user->is_active,
        ];
    }
}
