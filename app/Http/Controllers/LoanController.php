<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Book;
use App\Models\Member;
use App\Http\Responses\ApiResponse;

class LoanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Loan::with(['items', 'member'])->orderBy('loaned_at', 'desc');

        if ($memberId = $request->query('member_id')) {
            $query->where('member_id', $memberId);
        }

        if ($from = $request->query('from')) {
            $query->where('loaned_at', '>=', Carbon::parse($from)->startOfDay());
        }

        if ($to = $request->query('to')) {
            $query->where('loaned_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $loans = $query->get();

        // Filtro por estado calculado (Artículo VII)
        if ($statusFilter = $request->query('status')) {
            $loans = $loans->filter(fn(Loan $l) => $l->calculated_status === $statusFilter)->values();
        }

        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));
        $total = $loans->count();

        $paginated = $loans->slice(($page - 1) * $size, $size)->values();
        $items = $paginated->map(fn(Loan $l) => $this->formatLoan($l))->toArray();

        return ApiResponse::paginated($items, $page, $size, $total);
    }

    public function show(string $id): JsonResponse
    {
        $loan = Loan::with(['items', 'member'])->find($id);
        if (!$loan) {
            return ApiResponse::error('NOT_FOUND', 'El préstamo no existe.', [], 404);
        }

        return response()->json($this->formatLoan($loan));
    }

    public function store(Request $request): JsonResponse
    {
        $memberId = (string) $request->input('member_id');
        $itemsData = (array) $request->input('items', []);

        $member = Member::find($memberId);
        if (!$member) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El socio especificado no existe.',
                [['field' => 'member_id', 'issue' => 'socio no encontrado']],
                422
            );
        }

        // CA-06.1: 1 a 3 líneas
        $itemCount = count($itemsData);
        if ($itemCount < 1 || $itemCount > 3) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Un préstamo debe contener entre 1 y 3 libros.',
                [['field' => 'items', 'issue' => 'debe contener de 1 a 3 elementos']],
                422
            );
        }

        // CA-06.2: No se repite el mismo libro en un préstamo
        $bookIds = array_map(fn($item) => $item['book_id'] ?? null, $itemsData);
        if (count($bookIds) !== count(array_unique($bookIds))) {
            return ApiResponse::error(
                'DUPLICATE_BOOK_IN_LOAN',
                'No se puede repetir el mismo libro en un solo préstamo.',
                [['field' => 'items', 'issue' => 'libros duplicados en la solicitud']],
                422
            );
        }

        // CA-06.5: Un socio con algún libro vencido no puede pedir otro
        $now = Carbon::now('UTC');
        $hasOverdue = LoanItem::whereHas('loan', fn($q) => $q->where('member_id', $memberId))
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->exists();

        if ($hasOverdue) {
            return ApiResponse::error(
                'MEMBER_HAS_OVERDUE',
                'El socio tiene libros con préstamo vencido. Debe devolverlos antes de solicitar nuevos préstamos.',
                [],
                409
            );
        }

        // CA-06.4: Máximo 3 libros sin devolver en total
        $activeLoansCount = LoanItem::whereHas('loan', fn($q) => $q->where('member_id', $memberId))
            ->whereNull('returned_at')
            ->count();

        if ($activeLoansCount + $itemCount > 3) {
            return ApiResponse::error(
                'MEMBER_LIMIT_EXCEEDED',
                "El socio actualmente tiene {$activeLoansCount} libro(s) sin devolver. El nuevo préstamo superaría el límite de 3 libros.",
                [['field' => 'items', 'issue' => "límite superado (actuales: {$activeLoansCount})"]],
                422
            );
        }

        // Transacción atómica: descontar ejemplar y crear líneas (RNF-2, CA-06.3)
        return DB::transaction(function () use ($memberId, $bookIds, $request, $now) {
            $booksToLend = [];

            foreach ($bookIds as $bookId) {
                // Bloqueo pesimista para concurrencia (RNF-2: dos bibliotecarios prestando a la vez)
                $book = Book::active()->with('category')->lockForUpdate()->find($bookId);

                if (!$book) {
                    return ApiResponse::error(
                        'NOT_FOUND',
                        "El libro solicitado ({$bookId}) no existe o está inactivo.",
                        [],
                        404
                    );
                }

                // CA-06.3: Sin ejemplares disponibles responde 409 BOOK_NOT_AVAILABLE
                if ($book->available_copies < 1) {
                    return ApiResponse::error(
                        'BOOK_NOT_AVAILABLE',
                        "No quedan ejemplares disponibles de «{$book->title}».",
                        [['field' => 'book_id', 'issue' => 'sin ejemplares']],
                        409
                    );
                }

                $booksToLend[] = $book;
            }

            $user = $request->user();
            $loanId = (string) Str::uuid();

            $loan = Loan::create([
                'id' => $loanId,
                'member_id' => $memberId,
                'loaned_by_user_id' => $user->id,
                'loaned_at' => $now,
            ]);

            $createdItems = [];
            foreach ($booksToLend as $book) {
                // Descontar disponible
                $book->decrement('available_copies', 1);

                // CA-06.6: Congelar título, ISBN y nombre de categoría en ese instante; due_at = +14 días
                $item = LoanItem::create([
                    'id' => (string) Str::uuid(),
                    'loan_id' => $loanId,
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'isbn' => $book->isbn,
                    'category_name' => $book->category->name,
                    'due_at' => $now->copy()->addDays(14),
                    'returned_at' => null,
                ]);

                $createdItems[] = $item;
            }

            $loan->setRelation('items', collect($createdItems));
            return response()->json($this->formatLoan($loan), 201);
        });
    }

    public function returnItem(string $loanId, string $itemId): JsonResponse
    {
        return DB::transaction(function () use ($loanId, $itemId) {
            $item = LoanItem::where('loan_id', $loanId)->where('id', $itemId)->lockForUpdate()->first();

            if (!$item) {
                return ApiResponse::error('NOT_FOUND', 'La línea de préstamo especificada no existe.', [], 404);
            }

            // CA-07.2: Devolver una línea ya devuelta: 409 ITEM_ALREADY_RETURNED
            if ($item->returned_at !== null) {
                return ApiResponse::error(
                    'ITEM_ALREADY_RETURNED',
                    'Este libro ya fue devuelto con anterioridad.',
                    [],
                    409
                );
            }

            // CA-07.1: Pone returned_at y suma 1 a los disponibles
            $now = Carbon::now('UTC');
            $item->update(['returned_at' => $now]);

            $book = Book::lockForUpdate()->find($item->book_id);
            if ($book) {
                $book->increment('available_copies', 1);
            }

            return response()->json([
                'id' => $item->id,
                'loan_id' => $item->loan_id,
                'book_id' => $item->book_id,
                'book_title' => $item->book_title,
                'isbn' => $item->isbn,
                'category_name' => $item->category_name,
                'due_at' => $item->due_at->toIso8601ZuluString(),
                'returned_at' => $item->returned_at->toIso8601ZuluString(),
            ]);
        });
    }

    public function overdue(): JsonResponse
    {
        $now = Carbon::now('UTC');

        // CA-09.1 & CA-09.2: Líneas sin devolver vencidas ordenadas por más retraso (due_at asc)
        $overdueItems = LoanItem::with('loan.member')
            ->whereNull('returned_at')
            ->where('due_at', '<', $now)
            ->orderBy('due_at', 'asc')
            ->get();

        $rows = $overdueItems->map(fn(LoanItem $item) => [
            'loan_id' => $item->loan_id,
            'item_id' => $item->id,
            'book_title' => $item->book_title,
            'member_name' => $item->loan->member->full_name ?? 'Desconocido',
            'due_at' => $item->due_at->toIso8601ZuluString(),
            'days_late' => (int) $item->due_at->diffInDays($now),
        ])->toArray();

        return response()->json(['items' => $rows]);
    }

    private function formatLoan(Loan $loan): array
    {
        return [
            'id' => $loan->id,
            'member_id' => $loan->member_id,
            'loaned_at' => $loan->loaned_at->toIso8601ZuluString(),
            'status' => $loan->calculated_status,
            'items' => $loan->items->map(fn(LoanItem $item) => [
                'id' => $item->id,
                'book_id' => $item->book_id,
                'book_title' => $item->book_title,
                'isbn' => $item->isbn,
                'category_name' => $item->category_name,
                'due_at' => $item->due_at->toIso8601ZuluString(),
                'returned_at' => $item->returned_at ? $item->returned_at->toIso8601ZuluString() : null,
            ])->toArray(),
        ];
    }
}
