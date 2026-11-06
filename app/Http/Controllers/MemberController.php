<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use App\Models\Member;
use App\Models\LoanItem;
use App\Http\Responses\ApiResponse;

class MemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Member::query();

        if ($search = $request->query('q')) {
            $search = strtolower(trim($search));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(full_name) LIKE ?', ["%{$search}%"])
                  ->orWhere('document_number', 'LIKE', "%{$search}%");
            });
        }

        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $total = $query->count();
        $members = $query->orderBy('full_name', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $members->map(fn(Member $m) => [
            'id' => $m->id,
            'full_name' => $m->full_name,
            'document_number' => $m->document_number,
        ])->toArray();

        return ApiResponse::paginated($items, $page, $size, $total);
    }

    public function show(string $id): JsonResponse
    {
        $member = Member::find($id);
        if (!$member) {
            return ApiResponse::error('NOT_FOUND', 'El socio solicitado no existe.', [], 404);
        }

        // Conteo de libros sin devolver (activas)
        $activeLoansCount = LoanItem::whereHas('loan', fn($q) => $q->where('member_id', $id))
            ->whereNull('returned_at')
            ->count();

        return response()->json([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
            'active_loans' => $activeLoansCount,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $fullName = trim((string) $request->input('full_name'));
        $documentNumber = trim((string) $request->input('document_number'));

        if (!$fullName || !$documentNumber) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El nombre completo y el número de documento son obligatorios.',
                [],
                422
            );
        }

        // CA-05.1: Documento único
        $existing = Member::where('document_number', $documentNumber)->first();
        if ($existing) {
            return ApiResponse::error(
                'DOCUMENT_TAKEN',
                "El documento de identidad {$documentNumber} ya se encuentra registrado.",
                [['field' => 'document_number', 'issue' => 'documento repetido']],
                409
            );
        }

        $member = Member::create([
            'id' => (string) Str::uuid(),
            'full_name' => $fullName,
            'document_number' => $documentNumber,
        ]);

        return response()->json([
            'id' => $member->id,
            'full_name' => $member->full_name,
            'document_number' => $member->document_number,
        ], 201);
    }
}
