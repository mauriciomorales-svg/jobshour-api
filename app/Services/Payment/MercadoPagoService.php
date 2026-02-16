<?php

namespace App\Services\Payment;

use App\Models\Payment;

class MercadoPagoService
{
    protected $accessToken;

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token');
    }

    public function createPaymentIntent(Payment $payment, float $amount): array
    {
        return [
            'client_secret' => $this->accessToken,
            'public_key' => config('services.mercadopago.public_key'),
        ];
    }

    public function confirmPayment(Payment $payment): array
    {
        return ['success' => true];
    }
}
