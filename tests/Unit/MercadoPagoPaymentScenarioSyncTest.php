<?php

namespace Tests\Unit;

use App\Models\ServiceRequest;
use App\Services\MercadoPagoServicePaymentHelper;
use Mockery;
use Tests\TestCase;

class MercadoPagoPaymentScenarioSyncTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function scenarioProvider(): array
    {
        return [
            'APRO approved' => ['APRO', 'approved', 'completed'],
            'APRO authorized (retención prod)' => ['APRO', 'authorized', 'pending'],
            'OTHE rejected' => ['OTHE', 'rejected', 'failed'],
            'CONT pending' => ['CONT', 'pending', 'pending'],
            'CONT in_process' => ['CONT', 'in_process', 'pending'],
            'CALL rejected' => ['CALL', 'rejected', 'failed'],
            'FUND rejected' => ['FUND', 'rejected', 'failed'],
            'SECU rejected' => ['SECU', 'rejected', 'failed'],
            'EXPI rejected' => ['EXPI', 'rejected', 'failed'],
            'FORM rejected' => ['FORM', 'rejected', 'failed'],
            'INST rejected' => ['INST', 'rejected', 'failed'],
            'DUPL rejected' => ['DUPL', 'rejected', 'failed'],
            'LOCK rejected' => ['LOCK', 'rejected', 'failed'],
            'CTNA rejected' => ['CTNA', 'rejected', 'failed'],
            'ATTE rejected' => ['ATTE', 'rejected', 'failed'],
            'BLAC rejected' => ['BLAC', 'rejected', 'failed'],
            'UNSU rejected' => ['UNSU', 'rejected', 'failed'],
            'TEST rejected' => ['TEST', 'rejected', 'failed'],
            'refunded' => ['APRO', 'refunded', 'refunded'],
            'cancelled' => ['OTHE', 'cancelled', 'failed'],
        ];
    }

    /**
     * @dataProvider scenarioProvider
     */
    public function test_sync_service_request_from_mp_payment(string $holder, string $mpStatus, string $expectedPaymentStatus): void
    {
        /** @var ServiceRequest&\Mockery\MockInterface $sr */
        $sr = Mockery::mock(ServiceRequest::class)->makePartial();
        $sr->payment_status = 'pending';
        $sr->mp_status = null;
        $sr->paid_at = null;
        $sr->shouldReceive('update')->once()->andReturnUsing(function (array $updates) use ($sr) {
            foreach ($updates as $key => $value) {
                $sr->{$key} = $value;
            }

            return true;
        });

        app(MercadoPagoServicePaymentHelper::class)->syncServiceRequestFromMpPayment($sr, [
            'id' => 999001,
            'status' => $mpStatus,
            'status_detail' => 'scenario_' . $holder,
            'transaction_amount' => 1080,
            'currency_id' => 'CLP',
        ]);

        $this->assertSame($expectedPaymentStatus, $sr->payment_status, "Holder {$holder} / MP {$mpStatus}");
        $this->assertSame($mpStatus, $sr->mp_status);
    }

    public function test_sandbox_uses_immediate_capture(): void
    {
        config(['mercadopago.use_sandbox_checkout' => true, 'app.env' => 'production']);
        $this->assertTrue(MercadoPagoServicePaymentHelper::shouldCaptureImmediately());

        config(['mercadopago.use_sandbox_checkout' => false, 'app.env' => 'production']);
        $this->assertFalse(MercadoPagoServicePaymentHelper::shouldCaptureImmediately());
    }
}
