<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MpWebhookEvent;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\Worker;
use App\Support\AdminGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    private function assertAdmin(Request $request): void
    {
        AdminGate::assert($request);
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
     * Destacar demanda en mapa (orden por proximidad: boosted primero).
     */
    public function boostDemand(Request $request, int $id)
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'hours' => 'nullable|integer|min:1|max:336',
        ]);

        $hours = $validated['hours'] ?? 24;
        $sr = ServiceRequest::findOrFail($id);
        $sr->boosted_until = now()->addHours($hours);
        $sr->save();

        return response()->json([
            'status' => 'success',
            'boosted_until' => $sr->boosted_until->toIso8601String(),
        ]);
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

    /**
     * Últimas transacciones procesadas por webhooks MP
     */
    public function transactions(Request $request)
    {
        $this->assertAdmin($request);

        $events = MpWebhookEvent::orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['id', 'mp_payment_id', 'event_type', 'external_reference', 'mp_status', 'result', 'created_at']);

        // Enriquecer con monto real si está disponible en service_requests
        $srIds = $events->where('event_type', 'service_payment')
            ->map(fn($e) => is_numeric($e->external_reference) ? (int)$e->external_reference : null)
            ->filter()->values();

        $srPrices = ServiceRequest::whereIn('id', $srIds)->pluck('offered_price', 'id');

        $enriched = $events->map(function ($e) use ($srPrices) {
            $row = $e->toArray();
            if ($e->event_type === 'service_payment' && is_numeric($e->external_reference)) {
                $row['amount_clp'] = $srPrices[(int)$e->external_reference] ?? null;
            }
            return $row;
        });

        return response()->json([
            'total' => MpWebhookEvent::count(),
            'approved_today' => MpWebhookEvent::where('mp_status', 'approved')
                ->whereDate('created_at', today())->count(),
            'data' => $enriched,
        ]);
    }

    /**
     * Lista de espera (zonas sin cobertura)
     */
    public function waitlist(Request $request)
    {
        $this->assertAdmin($request);

        $entries = WaitlistEntry::orderBy('created_at', 'desc')
            ->limit(100)
            ->get(['id', 'email', 'phone', 'lat', 'lng', 'notified', 'created_at']);

        return response()->json([
            'total' => WaitlistEntry::count(),
            'not_notified' => WaitlistEntry::where('notified', false)->count(),
            'data' => $entries,
        ]);
    }

    /**
     * Crear demanda en nombre de un cliente (modo fundador intermediario)
     */
    public function createDemandForClient(Request $request)
    {
        $this->assertAdmin($request);

        $data = $request->validate([
            'description'  => 'required|string|max:500',
            'category_id'  => 'required|integer|exists:categories,id',
            'offered_price'=> 'nullable|numeric|min:0',
            'lat'          => 'required|numeric|between:-90,90',
            'lng'          => 'required|numeric|between:-180,180',
            'client_name'  => 'nullable|string|max:100',
            'client_phone' => 'nullable|string|max:32',
            'urgency'      => 'nullable|in:normal,urgent',
        ]);

        // Usar el usuario admin como client_id temporal (placeholder)
        $adminUser = $request->user();

        $sr = ServiceRequest::create([
            'client_id'     => $adminUser->id,
            'category_id'   => $data['category_id'],
            'description'   => $data['description'] . ($data['client_name'] ? "\n\n[Cliente: {$data['client_name']}]" : ''),
            'offered_price' => $data['offered_price'] ?? 0,
            'lat'           => $data['lat'],
            'lng'           => $data['lng'],
            'status'        => 'pending',
            'type'          => 'map_take',
            'urgency'       => $data['urgency'] ?? 'normal',
            'source'        => 'admin_manual',
            'expires_at'    => now()->addHours(24),
        ]);

        return response()->json([
            'status' => 'ok',
            'demand_id' => $sr->id,
            'message'   => "Demanda #{$sr->id} creada. Avisa al cliente por WhatsApp que su pedido está publicado.",
        ]);
    }

    /**
     * Workers activos ahora mismo
     */
    public function activeWorkers(Request $request)
    {
        $this->assertAdmin($request);

        $workers = Worker::with('user:id,name,email,phone')
            ->where('availability_status', 'active')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->limit(50)
            ->get(['id', 'user_id', 'availability_status', 'lat', 'lng', 'updated_at']);

        return response()->json([
            'active_count' => $workers->count(),
            'intermediate_count' => Worker::where('availability_status', 'intermediate')->count(),
            'data' => $workers,
        ]);
    }
}
