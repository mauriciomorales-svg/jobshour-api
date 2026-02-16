<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = DB::table('categories')
            ->select('id', 'slug', 'name', 'icon', 'color', 'active_count')
            ->where('active_count', '>', 0)
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
    }
}
