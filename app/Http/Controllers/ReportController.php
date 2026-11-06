<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Responses\ApiResponse;

class ReportController extends Controller
{
    public function mostBorrowed(Request $request): JsonResponse
    {
        $fromStr = $request->query('from');
        $toStr = $request->query('to');

        if (!$fromStr || !$toStr) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Los parámetros «from» y «to» son obligatorios en formato AAAA-MM-DD.',
                [],
                422
            );
        }

        try {
            $from = Carbon::parse($fromStr)->startOfDay();
            $to = Carbon::parse($toStr)->endOfDay();
        } catch (\Exception $e) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Formato de fecha inválido.',
                [],
                422
            );
        }

        // CA-10.2: Fin anterior al inicio responde 422 INVALID_DATE_RANGE
        if ($to->lt($from)) {
            return ApiResponse::error(
                'INVALID_DATE_RANGE',
                'La fecha final no puede ser anterior a la fecha inicial.',
                [['field' => 'to', 'issue' => 'fecha fin anterior a fecha inicio']],
                422
            );
        }

        // CA-10.1 & CA-10.4: Agregación por libro y metadatos congelados
        $results = DB::table('library.loan_item as li')
            ->join('library.loan as l', 'l.id', '=', 'li.loan_id')
            ->whereBetween('l.loaned_at', [$from, $to])
            ->select(
                'li.book_id',
                'li.book_title',
                'li.category_name',
                DB::raw('COUNT(li.id) as times_borrowed')
            )
            ->groupBy('li.book_id', 'li.book_title', 'li.category_name')
            ->orderByDesc('times_borrowed')
            ->orderBy('li.book_title', 'asc')
            ->get();

        $rows = $results->map(fn($row) => [
            'book_id' => $row->book_id,
            'book_title' => $row->book_title,
            'category_name' => $row->category_name,
            'times_borrowed' => (int) $row->times_borrowed,
        ])->toArray();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows,
        ]);
    }
}
