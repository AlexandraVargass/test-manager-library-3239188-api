<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Http\Responses\ApiResponse;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $rawUsername = (string) $request->input('username');
        $username = strtolower(trim($rawUsername));
        $password = (string) $request->input('password');
        $role = (string) $request->input('role');

        if (!$username || !$password) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El nombre de usuario y la contraseña son requeridos.',
                [['field' => 'username', 'issue' => 'requerido']],
                422
            );
        }

        // CA-02.3: Pedir el rol 'admin' por la API responde 422
        if ($role !== 'librarian') {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Solo se permite crear usuarios con el rol «librarian».',
                [['field' => 'role', 'issue' => 'el rol solo puede ser librarian']],
                422
            );
        }

        // CA-02.2: Repetido responde 409 USERNAME_TAKEN
        $existing = User::where('username', $username)->first();
        if ($existing) {
            return ApiResponse::error(
                'USERNAME_TAKEN',
                "El nombre de usuario «{$username}» ya se encuentra registrado.",
                [['field' => 'username', 'issue' => 'nombre de usuario no disponible']],
                409
            );
        }

        $user = User::create([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'password_hash' => Hash::make($password),
            'role' => 'librarian',
        ]);

        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'role' => $user->role,
        ], 201);
    }
}
