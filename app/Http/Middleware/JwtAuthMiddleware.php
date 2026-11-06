<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\JwtService;
use App\Models\User;
use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Token de autenticación faltante o inválido.',
                [],
                401
            );
        }

        $token = substr($authHeader, 7);
        $payload = $this->jwtService->validateToken($token);

        if (!$payload) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'El token es inválido o ha expirado.',
                [],
                401
            );
        }

        $user = User::find($payload['sub']);
        if (!$user) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Usuario del token no encontrado.',
                [],
                401
            );
        }

        $request->setUserResolver(fn() => $user);
        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_role', $payload['role']);

        return $next($request);
    }
}
