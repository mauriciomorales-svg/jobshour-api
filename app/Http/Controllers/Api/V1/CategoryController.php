<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
        try {
            $categories = DB::table('categories')
                ->select('id', 'slug', 'display_name', 'icon', 'color', 'sort_order', 'is_active')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $categories->map(fn($c) => [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $c->display_name,
                    'icon' => $c->icon,
                    'color' => $c->color,
                    'active_count' => $c->sort_order,
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
