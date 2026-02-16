<?php

namespace App\Services\Payment;

use App\Models\Payment;

class StripeService
{
    protected $secretKey;

    public function __construct()
    {
        $this->secretKey = config('services.stripe.secret');
    }

    public function createPaymentIntent(Payment $payment, float $amount): array
    {
        return [
            'client_secret' => 'pi_' . $payment->id . '_secret_' . uniqid(),
            'public_key' => config('services.stripe.key'),
        ];
    }

    public function confirmPayment(Payment $payment): array
    {
        return ['success' => true];
    }
}
