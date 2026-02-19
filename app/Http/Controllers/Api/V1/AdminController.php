<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ServiceRequest;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Verificar que el usuario sea admin (user_id = 21 = Mauricio Morales)
     */
    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !in_array($user->id, [21])) {
            abort(403, 'No autorizado');
        }
    }

    /**
     * Dashboard stats
     */
    public function stats(Request $request)
    {
        $this->assertAdmin($request);

        return response()->json([
            'users' => [
                'total' => User::count(),
                'workers' => User::where('type', 'worker')->count(),
                'clients' => User::where('type', 'client')->count(),
                'with_fcm' => User::whereNotNull('fcm_token')->count(),
                'recent_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'demands' => [
                'total' => ServiceRequest::count(),
                'pending' => ServiceRequest::where('status', 'pending')->count(),
                'taken' => ServiceRequest::whereIn('status', ['taken', 'accepted'])->count(),
                'completed' => ServiceRequest::where('status', 'completed')->count(),
                'cancelled' => ServiceRequest::where('status', 'cancelled')->count(),
                'today' => ServiceRequest::whereDate('created_at', today())->count(),
                'week' => ServiceRequest::where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'categories' => Category::count(),
            'revenue' => [
                'total' => ServiceRequest::where('status', 'completed')->sum('final_price') ?: ServiceRequest::where('status', 'completed')->sum('offered_price'),
                'week' => ServiceRequest::where('status', 'completed')->where('completed_at', '>=', now()->subDays(7))->sum('offered_price'),
            ],
        ]);
    }

    /**
     * Listar usuarios con paginación y búsqueda
     */
    public function users(Request $request)
    {
        $this->assertAdmin($request);

        $query = User::query()->select('id', 'name', 'email', 'phone', 'type', 'nickname', 'is_active', 'is_pioneer', 'is_business', 'fcm_token', 'created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%")
                  ->orWhere('phone', 'ilike', "%{$search}%")
                  ->orWhere('nickname', 'ilike', "%{$search}%");
            });
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(20);

        $users->getCollection()->transform(function ($u) {
            $u->has_fcm = !empty($u->fcm_token);
            unset($u->fcm_token);
            return $u;
        });

        return response()->json($users);
    }

    /**
     * Detalle de un usuario
     */
    public function userDetail(Request $request, int $id)
    {
        $this->assertAdmin($request);

        $user = User::with('worker')->findOrFail($id);
        $demands = ServiceRequest::where('client_id', $id)->orWhere('worker_id', $user->worker?->id)->latest()->limit(10)->get(['id', 'description', 'status', 'offered_price', 'type', 'created_at']);

        return response()->json([
            'user' => $user->makeHidden(['password', 'remember_token', 'fcm_token']),
            'demands' => $demands,
        ]);
    }

    /**
     * Activar/desactivar un usuario
     */
    public function toggleUser(Request $request, int $id)
    {
        $this->assertAdmin($request);

        $user = User::findOrFail($id);
        $user->is_active = !$user->is_active;
        $user->save();

        return response()->json([
            'status' => 'success',
            'is_active' => $user->is_active,
        ]);
    }

    /**
     * Listar demandas con filtros
     */
    public function demands(Request $request)
    {
        $this->assertAdmin($request);

        $query = ServiceRequest::with(['client:id,name,nickname', 'category:id,display_name,color'])
            ->select('id', 'client_id', 'category_id', 'description', 'status', 'offered_price', 'type', 'urgency', 'created_at', 'completed_at', 'workers_needed', 'recurrence');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($search = $request->input('search')) {
            $query->where('description', 'ilike', "%{$search}%");
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')->paginate(20)
        );
    }

    /**
     * Cancelar una demanda (admin)
     */
    public function cancelDemand(Request $request, int $id)
    {
        $this->assertAdmin($request);

        $sr = ServiceRequest::findOrFail($id);
        $sr->status = 'cancelled';
        $sr->cancelled_at = now();
        $sr->cancelled_by = 'admin';
        $sr->cancellation_reason = $request->input('reason', 'Cancelado por administrador');
        $sr->save();

        return response()->json(['status' => 'success']);
    }

    /**
     * Listar categorías con conteo de workers y demandas
     */
    public function categories(Request $request)
    {
        $this->assertAdmin($request);

        $cats = Category::withCount(['workers', 'serviceRequests'])->orderBy('display_name')->get();

        return response()->json($cats);
    }
}
