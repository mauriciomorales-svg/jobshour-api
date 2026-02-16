<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\WorkSession;
use App\Services\Payment\MercadoPagoService;
use App\Services\Payment\StripeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    protected $mercadoPago;
    protected $stripe;

    public function __construct(MercadoPagoService $mercadoPago, StripeService $stripe)
    {
        $this->mercadoPago = $mercadoPago;
        $this->stripe = $stripe;
    }

    public function createIntent(Request $request)
    {
        $validated = $request->validate([
            'work_session_id' => 'required|exists:work_sessions,id',
            'payment_method' => 'required|in:mercadopago,stripe',
        ]);

        $workSession = WorkSession::findOrFail($validated['work_session_id']);
        
        if ($workSession->employer_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $amount = $workSession->total_amount;

        $payment = Payment::create([
            'work_session_id' => $workSession->id,
            'employer_id' => auth()->id(),
            'worker_id' => $workSession->worker_id,
            'amount' => $amount,
            'payment_method' => $validated['payment_method'],
            'status' => 'pending',
        ]);

        $service = $validated['payment_method'] === 'mercadopago' 
            ? $this->mercadoPago 
            : $this->stripe;

        $intent = $service->createPaymentIntent($payment, $amount);

        return response()->json([
            'payment_id' => $payment->id,
            'client_secret' => $intent['client_secret'],
            'public_key' => $intent['public_key'],
        ]);
    }

    public function confirm(Request $request, Payment $payment)
    {
        if ($payment->employer_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $service = $payment->payment_method === 'mercadopago' 
            ? $this->mercadoPago 
            : $this->stripe;

        $result = $service->confirmPayment($payment);

        if ($result['success']) {
            DB::transaction(function () use ($payment) {
                $payment->update(['status' => 'completed', 'completed_at' => now()]);
                $payment->workSession->update(['payment_status' => 'completed']);
                $payment->workSession->worker->increment('earnings', $payment->amount);
            });
        }

        return response()->json([
            'status' => $payment->status,
            'success' => $result['success'],
        ]);
    }

    public function wallet(Request $request)
    {
        $user = auth()->user();
        
        return response()->json([
            'balance' => $user->wallet_balance,
            'pending' => $user->wallet_pending,
            'withdrawable' => $user->wallet_withdrawable,
        ]);
    }

    public function history(Request $request)
    {
        $payments = Payment::where('employer_id', auth()->id())
            ->orWhere('worker_id', auth()->id())
            ->with(['workSession:id,started_at,ended_at', 'employer:id,name', 'worker:id,name'])
            ->latest()
            ->paginate($request->input('per_page', 20));

        return response()->json($payments);
    }
}
