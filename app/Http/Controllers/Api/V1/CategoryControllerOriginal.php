<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;

class CategoryController extends Controller
{
    public function index()
    {
        try {
            $categories = Category::query()
                ->select('id', 'slug', 'name', 'icon', 'color', 'active_count')
                ->orderBy('active_count', 'desc')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $categories->map(fn($c) => [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $c->name,
                    'icon' => $c->icon,
                    'color' => $c->color,
                    'active_count' => (int)$c->active_count,
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
