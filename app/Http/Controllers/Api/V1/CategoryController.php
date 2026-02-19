<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{
    public function index()
    {
        try {
            // Log de inicio para debugging
            Log::info('CategoryController::index called');
            
            $categories = DB::table('categories')
                ->select('id', 'slug', 'display_name', 'icon', 'color', 'sort_order', 'is_active')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get();

            Log::info('Categories fetched', ['count' => $categories->count()]);

            // OPTIMIZADO: Contar workers activos por categoría en una sola consulta
            $categoryIds = $categories->pluck('id');
            $workerCounts = DB::table('workers')
                ->select('category_id', DB::raw('COUNT(*) as count'))
                ->whereIn('category_id', $categoryIds)
                ->where('availability_status', 'active')
                ->groupBy('category_id')
                ->pluck('count', 'category_id')
                ->toArray();

            $categoriesWithCount = $categories->map(function($c) use ($workerCounts) {
                return [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $c->display_name,
                    'icon' => $c->icon,
                    'color' => $c->color,
                    'active_count' => $workerCounts[$c->id] ?? 0,
                ];
            });

            Log::info('CategoryController::index success', ['count' => $categoriesWithCount->count()]);
            
            return response()->json($categoriesWithCount);
        } catch (\Exception $e) {
            Log::error('CategoryController error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // En producción, retornar error más simple
            return response()->json([
                'error' => true,
                'message' => config('app.debug') ? $e->getMessage() : 'Error al cargar categorías',
            ], 500);
        }
    }

    /**
     * Obtener todas las categorías (incluyendo inactivas) - Para administración
     */
    public function all()
    {
        try {
            $categories = Category::orderBy('sort_order')->get()->map(function($c) {
                return [
                    'id' => $c->id,
                    'slug' => $c->slug,
                    'name' => $c->display_name,
                    'icon' => $c->icon,
                    'color' => $c->color,
                    'sort_order' => $c->sort_order,
                    'is_active' => $c->is_active,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $categories,
            ]);
        } catch (\Exception $e) {
            Log::error('CategoryController::all error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al cargar categorías',
            ], 500);
        }
    }

    /**
     * Crear nueva categoría
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'slug' => 'required|string|max:255|unique:categories,slug',
                'display_name' => 'required|string|max:255',
                'icon' => 'required|string|max:50',
                'color' => 'required|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
                'sort_order' => 'nullable|integer|min:0',
                'is_active' => 'nullable|boolean',
            ]);

            // Obtener el siguiente sort_order si no se proporciona
            if (!isset($validated['sort_order'])) {
                $maxOrder = Category::max('sort_order') ?? 0;
                $validated['sort_order'] = $maxOrder + 1;
            }

            $category = Category::create([
                'slug' => $validated['slug'],
                'display_name' => $validated['display_name'],
                'icon' => $validated['icon'],
                'color' => $validated['color'],
                'sort_order' => $validated['sort_order'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Categoría creada exitosamente',
                'data' => $category,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('CategoryController::store error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al crear categoría',
            ], 500);
        }
    }

    /**
     * Actualizar categoría existente
     */
    public function update(Request $request, Category $category)
    {
        try {
            $validated = $request->validate([
                'slug' => 'sometimes|string|max:255|unique:categories,slug,' . $category->id,
                'display_name' => 'sometimes|string|max:255',
                'icon' => 'sometimes|string|max:50',
                'color' => 'sometimes|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
                'sort_order' => 'sometimes|integer|min:0',
                'is_active' => 'sometimes|boolean',
            ]);

            $category->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Categoría actualizada exitosamente',
                'data' => $category->fresh(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('CategoryController::update error', [
                'error' => $e->getMessage(),
                'category_id' => $category->id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al actualizar categoría',
            ], 500);
        }
    }

    /**
     * Eliminar categoría (soft delete - desactivar)
     */
    public function destroy(Request $request, Category $category)
    {
        try {
            // Verificar si hay workers usando esta categoría
            $workersCount = DB::table('workers')
                ->where('category_id', $category->id)
                ->count();

            if ($workersCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => "No se puede eliminar la categoría porque tiene {$workersCount} trabajador(es) asociado(s). Desactívala en su lugar.",
                ], 422);
            }

            // En lugar de eliminar físicamente, desactivar
            $category->update(['is_active' => false]);

            return response()->json([
                'status' => 'success',
                'message' => 'Categoría desactivada exitosamente',
            ]);
        } catch (\Exception $e) {
            Log::error('CategoryController::destroy error', [
                'error' => $e->getMessage(),
                'category_id' => $category->id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => config('app.debug') ? $e->getMessage() : 'Error al eliminar categoría',
            ], 500);
        }
    }
}
