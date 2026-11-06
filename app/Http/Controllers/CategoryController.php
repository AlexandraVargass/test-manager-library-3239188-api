<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::orderBy('name', 'asc')->get(['id', 'name']);
        return response()->json($categories);
    }
}
