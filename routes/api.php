<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\ReportController;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Middleware\RoleMiddleware;

/*
|--------------------------------------------------------------------------
| Manager Library API Routes (Contrato api-contract.md)
| Base: /api/v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // --- 3.1 Rutas Públicas (Salud y Autenticación) ---
    Route::get('/health', [AuthController::class, 'health']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // --- Rutas Protegidas por JWT ---
    Route::middleware([JwtAuthMiddleware::class])->group(function () {

        // 3.3 Categorías (Autenticado)
        Route::get('/categories', [CategoryController::class, 'index']);

        // 3.4 Libros - Lectura (Autenticado)
        Route::get('/books', [BookController::class, 'index']);
        Route::get('/books/{id}', [BookController::class, 'show']);
        Route::get('/books/{id}/cover', [BookController::class, 'getCover']);

        // 3.5 Socios (Admin y Librarian)
        Route::middleware([RoleMiddleware::class . ':admin,librarian'])->group(function () {
            Route::get('/members', [MemberController::class, 'index']);
            Route::get('/members/{id}', [MemberController::class, 'show']);
            Route::post('/members', [MemberController::class, 'store']);
        });

        // 3.6 Préstamos (Admin y Librarian)
        Route::middleware([RoleMiddleware::class . ':admin,librarian'])->group(function () {
            Route::post('/loans', [LoanController::class, 'store']);
            Route::get('/loans', [LoanController::class, 'index']);
            Route::get('/loans/overdue', [LoanController::class, 'overdue']);
            Route::get('/loans/{id}', [LoanController::class, 'show']);
            Route::post('/loans/{loan_id}/items/{item_id}/return', [LoanController::class, 'returnItem']);
        });

        // 3.2, 3.4, 3.7 Rutas exclusivas de Administrador (Role: admin)
        Route::middleware([RoleMiddleware::class . ':admin'])->group(function () {
            // Usuarios
            Route::post('/users', [UserController::class, 'store']);

            // Gestión de Libros
            Route::post('/books', [BookController::class, 'store']);
            Route::put('/books/{id}', [BookController::class, 'update']);
            Route::delete('/books/{id}', [BookController::class, 'destroy']);
            Route::put('/books/{id}/cover', [BookController::class, 'uploadCover']);
            Route::delete('/books/{id}/cover', [BookController::class, 'deleteCover']);

            // Reportes
            Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
        });
    });
});
