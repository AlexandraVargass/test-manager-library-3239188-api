<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\JwtService;
use App\Http\Responses\ApiResponse;

class AuthController extends Controller
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    public function login(Request $request): JsonResponse
    {
        $username = strtolower(trim((string) $request->input('username')));
        $password = (string) $request->input('password');

        if (!$username || !$password) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Credenciales de acceso incorrectas.',
                [],
                401
            );
        }

        $user = User::where('username', $username)->first();

        // CA-01.2: No revelar si falló el usuario o la clave
        if (!$user || !Hash::check($password, $user->password_hash)) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Credenciales de acceso incorrectas.',
                [],
                401
            );
        }

        $expiresIn = (int) env('JWT_TTL', 28800);
        $token = $this->jwtService->generateToken($user->id, $user->role);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'role' => $user->role,
        ]);
    }
}
