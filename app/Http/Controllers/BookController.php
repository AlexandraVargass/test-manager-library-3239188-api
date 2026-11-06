<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Models\Book;
use App\Models\Category;
use App\Models\LoanItem;
use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Book::with('category')->active();

        if ($search = $request->query('q')) {
            $search = strtolower(trim($search));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(author) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $page = max(1, (int) $request->query('page', 1));
        $size = min(50, max(1, (int) $request->query('size', 20)));

        $total = $query->count();
        $books = $query->orderBy('title', 'asc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $items = $books->map(fn(Book $book) => $this->formatBook($book))->toArray();

        return ApiResponse::paginated($items, $page, $size, $total);
    }

    public function show(string $id): JsonResponse
    {
        $book = Book::with('category')->active()->find($id);

        if (!$book) {
            return ApiResponse::error(
                'NOT_FOUND',
                'El libro solicitado no existe o ha sido dado de baja.',
                [],
                404
            );
        }

        return response()->json($this->formatBook($book));
    }

    public function store(Request $request): JsonResponse
    {
        $title = trim((string) $request->input('title'));
        $author = trim((string) $request->input('author'));
        $isbn = preg_replace('/\D/', '', (string) $request->input('isbn'));
        $categoryId = (string) $request->input('category_id');
        $totalCopies = (int) $request->input('total_copies');

        if (!$title || !$author || !$categoryId) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Título, autor y categoría son campos obligatorios.',
                [],
                422
            );
        }

        // CA-03.2: ISBN de 13 dígitos
        if (strlen($isbn) !== 13) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El ISBN debe tener exactamente 13 dígitos numéricos.',
                [['field' => 'isbn', 'issue' => 'longitud inválida']],
                422
            );
        }

        // CA-03.3: Total de ejemplares >= 1
        if ($totalCopies < 1) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El total de ejemplares debe ser mayor o igual a 1.',
                [['field' => 'total_copies', 'issue' => 'debe ser >= 1']],
                422
            );
        }

        $category = Category::find($categoryId);
        if (!$category) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'La categoría seleccionada no existe.',
                [['field' => 'category_id', 'issue' => 'categoría inválida']],
                422
            );
        }

        // CA-03.2: ISBN único entre activos
        $existing = Book::active()->where('isbn', $isbn)->first();
        if ($existing) {
            return ApiResponse::error(
                'ISBN_TAKEN',
                "Ya existe un libro activo registrado con el ISBN {$isbn}.",
                [['field' => 'isbn', 'issue' => 'ISBN ya registrado']],
                409
            );
        }

        $book = Book::create([
            'id' => (string) Str::uuid(),
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
            'category_id' => $categoryId,
            'total_copies' => $totalCopies,
            'available_copies' => $totalCopies, // CA-03.1: Arrancan iguales al total
            'cover_key' => null,
            'deleted_at' => null,
        ]);

        $book->load('category');
        return response()->json($this->formatBook($book), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $book = Book::active()->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe.', [], 404);
        }

        $title = trim((string) $request->input('title', $book->title));
        $author = trim((string) $request->input('author', $book->author));
        $categoryId = (string) $request->input('category_id', $book->category_id);
        $newTotal = $request->has('total_copies') ? (int) $request->input('total_copies') : $book->total_copies;

        if ($newTotal < 1) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El total de ejemplares debe ser mayor o igual a 1.',
                [],
                422
            );
        }

        // CA-03.3: Ajusta disponibles y nunca deja disponibles < 0
        $diff = $newTotal - $book->total_copies;
        $newAvailable = $book->available_copies + $diff;

        if ($newAvailable < 0) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'No se puede reducir el total por debajo de los ejemplares actualmente prestados.',
                [['field' => 'total_copies', 'issue' => 'disponibles quedarían en negativo']],
                422
            );
        }

        $book->update([
            'title' => $title,
            'author' => $author,
            'category_id' => $categoryId,
            'total_copies' => $newTotal,
            'available_copies' => $newAvailable,
        ]);

        $book->load('category');
        return response()->json($this->formatBook($book));
    }

    public function destroy(string $id): JsonResponse
    {
        $book = Book::active()->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe.', [], 404);
        }

        // CA-03.5: Un libro con préstamos abiertos no se da de baja
        $hasOpenLoans = LoanItem::where('book_id', $id)
            ->whereNull('returned_at')
            ->exists();

        if ($hasOpenLoans) {
            return ApiResponse::error(
                'BOOK_HAS_OPEN_LOANS',
                'No se puede dar de baja el libro porque tiene ejemplares en préstamos abiertos.',
                [],
                409
            );
        }

        // Baja lógica
        $book->update(['deleted_at' => Carbon::now('UTC')]);

        return response()->json(null, 204);
    }

    public function uploadCover(Request $request, string $id): JsonResponse
    {
        $book = Book::active()->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe.', [], 404);
        }

        if (!$request->hasFile('file') || !$request->file('file')->isValid()) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Debe proporcionar un archivo de imagen válido.',
                [],
                422
            );
        }

        $file = $request->file('file');

        // CA-03.4 & Seguridad §4: JPG/PNG, máx. 2 MB, validación por contenido MIME
        $mime = $file->getMimeType();
        if (!in_array($mime, ['image/jpeg', 'image/png'])) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'El formato de la portada debe ser JPG o PNG.',
                [],
                422
            );
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'La portada no puede superar los 2 MB de tamaño.',
                [],
                422
            );
        }

        $ext = $mime === 'image/png' ? 'png' : 'jpg';
        $opaqueKey = Str::uuid()->toString() . '.' . $ext;

        Storage::disk('local')->putFileAs('covers', $file, $opaqueKey);
        $book->update(['cover_key' => $opaqueKey]);

        return response()->json(['cover_key' => $opaqueKey]);
    }

    public function deleteCover(string $id): JsonResponse
    {
        $book = Book::active()->find($id);
        if (!$book) {
            return ApiResponse::error('NOT_FOUND', 'El libro no existe.', [], 404);
        }

        if ($book->cover_key) {
            $key = $book->cover_key;
            // Orden obligatorio según data-model.md §7: primero anula cover_key, luego borra binario
            $book->update(['cover_key' => null]);
            Storage::disk('local')->delete('covers/' . $key);
        }

        return response()->json(null, 204);
    }

    public function getCover(string $id)
    {
        $book = Book::active()->find($id);
        if (!$book || !$book->cover_key) {
            return ApiResponse::error('NOT_FOUND', 'El libro no tiene portada.', [], 404);
        }

        $path = storage_path('app/covers/' . $book->cover_key);
        if (!file_exists($path)) {
            return ApiResponse::error('NOT_FOUND', 'Archivo de portada no encontrado en disco.', [], 404);
        }

        return response()->file($path);
    }

    private function formatBook(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'category' => [
                'id' => $book->category->id,
                'name' => $book->category->name,
            ],
            'total_copies' => (int) $book->total_copies,
            'available_copies' => (int) $book->available_copies,
            'cover_key' => $book->cover_key,
        ];
    }
}
