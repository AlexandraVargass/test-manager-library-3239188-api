<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Respuesta estándar de error según api-contract.md §1
     */
    public static function error(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ], $status);
    }

    /**
     * Respuesta paginada estándar según api-contract.md §1
     */
    public static function paginated(array $items, int $page, int $size, int $total): JsonResponse
    {
        return response()->json([
            'items' => $items,
            'page' => $page,
            'size' => $size,
            'total' => $total,
        ]);
    }
}
