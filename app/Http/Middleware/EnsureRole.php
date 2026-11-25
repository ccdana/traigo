<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `role:control,management` deja pasar solo a esos roles (403 al resto). */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($request->user()?->role->value, $roles, true)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
