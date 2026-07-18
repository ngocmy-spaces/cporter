<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Admin user management (docs/SPEC.md §13). Admin role required (enforced on the routes).
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => User::query()->latest()->get(['id', 'name', 'email', 'role', 'created_at'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['admin', 'viewer'])],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        $this->audit->record('user.created', $user, ['email' => $user->email, 'role' => $user->role]);

        return response()->json(['data' => $user->only(['id', 'name', 'email', 'role', 'created_at'])], 201);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['error' => 'You cannot delete your own account.'], 422);
        }

        $user->delete();
        $this->audit->record('user.deleted', null, ['email' => $user->email]);

        return response()->json(['data' => true]);
    }
}
