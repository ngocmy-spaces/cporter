<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to users with a given role (docs/SPEC.md §13). Use after `auth`,
 * e.g. `->middleware(['auth', 'role:admin'])`. Viewers get read-only access.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if ($request->user()?->role !== $role) {
            return response()->json(['error' => "This action requires the '{$role}' role."], 403);
        }

        return $next($request);
    }
}
