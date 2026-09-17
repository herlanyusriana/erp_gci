<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckPermission
{
    /**
     * Middleware that requires at least one of the given permissions.
     * Usage: ->middleware('permission:part.view,part.create')
     */
    public function handle(Request $request, Closure $next, string ...$permissions): mixed
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        abort(403, __('You do not have permission to perform this action.'));
    }

    /**
     * Alias: gates a single permission from route actions.
     */
    public static function gate(string $permission): string
    {
        return 'permission:' . $permission;
    }
}
