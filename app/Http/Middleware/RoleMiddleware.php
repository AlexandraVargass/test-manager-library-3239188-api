<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles)) {
            return ApiResponse::error(
                'FORBIDDEN_ROLE',
                'No tiene los privilegios requeridos para ejecutar esta acción.',
                [],
                403
            );
        }

        return $next($request);
    }
}
