<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Admin authentication via Sanctum SPA (session cookie) — docs/SPEC.md §12, §13.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        app(AuditLogger::class)->record('auth.login', Auth::user(), [], Auth::user()?->email);

        return response()->json(['data' => Auth::user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => true]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }

    /**
     * Change the signed-in user's own password. Available to any authenticated
     * role — a user manages their own credential, no admin rights needed.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8), 'different:current_password'],
            'logout_other_devices' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        // Optional, user's choice: sign out this account's OTHER active sessions. This keeps
        // the current session valid and rehashes its stored password hash so it survives.
        $logoutOthers = $request->boolean('logout_other_devices');
        if ($logoutOthers) {
            Auth::logoutOtherDevices($data['password']);
        }
        $request->session()->regenerate();

        app(AuditLogger::class)->record('auth.password_changed', $user, [
            'logout_other_devices' => $logoutOthers,
        ], $user->email);

        return response()->json(['data' => true]);
    }
}
