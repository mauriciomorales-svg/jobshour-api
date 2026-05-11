<?php

namespace App\Events;

use App\Models\ServiceRequest;
use App\Services\FirebaseService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ServiceRequestUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ServiceRequest $serviceRequest) {}

    public function broadcastOn(): array
    {
        // La demanda puede estar "pending" y todavía no tener worker asignado.
        // En ese caso, el canal worker.* no existe y no debemos romper el broadcast.
        $channels = [
            new PrivateChannel('user.' . $this->serviceRequest->client_id),
        ];

        $workerUserId = $this->serviceRequest->worker?->user_id;
        if ($workerUserId) {
            $channels[] = new PrivateChannel('worker.' . $workerUserId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'request.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->serviceRequest->id,
            'status' => $this->serviceRequest->status,
            'final_price' => (int) $this->serviceRequest->final_price,
            'accepted_at' => $this->serviceRequest->accepted_at?->toISOString(),
            'completed_at' => $this->serviceRequest->completed_at?->toISOString(),
        ];
    }

    public function handle()
    {
        try {
            Log::info('[Push] ServiceRequestUpdated handle started', [
                'request_id' => $this->serviceRequest->id,
                'status' => $this->serviceRequest->status,
            ]);
            
            $firebase = new FirebaseService();
            $status = $this->serviceRequest->status;

            // Notificar al CLIENTE según el estado
            $client = \App\Models\User::find($this->serviceRequest->client_id);
            if ($client && $client->fcm_token) {
                Log::info('[Push] Client has FCM token', ['client_id' => $client->id]);
                
                if ($status === 'accepted') {
                    try {
                        $result = $firebase->sendToDevice(
                            $client->fcm_token,
                            '¡Trabajo aceptado!',
                            ($this->serviceRequest->worker?->user?->name ?? 'Trabajador') . ' aceptó tu solicitud',
                            [
                                'type' => 'request_accepted',
                                'request_id' => (string)$this->serviceRequest->id,
                            ]
                        );
                        if ($result) {
                            Log::info('[Push] FCM sent to client (accepted)');
                        } else {
                            Log::warning('[Push] FCM to client returned false (accepted)');
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[Push] FCM to client failed (non-critical)', ['error' => $e->getMessage()]);
                    }
                } elseif ($status === 'rejected') {
                    try {
                        $result = $firebase->sendToDevice(
                            $client->fcm_token,
                            'Solicitud rechazada',
                            'El worker no puede atender tu solicitud en este momento',
                            [
                                'type' => 'request_rejected',
                                'request_id' => (string)$this->serviceRequest->id,
                            ]
                        );
                        if ($result) {
                            Log::info('[Push] FCM sent to client (rejected)');
                        } else {
                            Log::warning('[Push] FCM to client returned false (rejected)');
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[Push] FCM to client failed (non-critical)', ['error' => $e->getMessage()]);
                    }
                } elseif ($status === 'completed') {
                    try {
                        $result = $firebase->sendToDevice(
                            $client->fcm_token,
                            '¡Trabajo completado!',
                            'Califica tu experiencia con ' . ($this->serviceRequest->worker?->user?->name ?? 'el trabajador'),
                            [
                                'type' => 'request_completed',
                                'request_id' => (string)$this->serviceRequest->id,
                            ]
                        );
                        if ($result) {
                            Log::info('[Push] FCM sent to client (completed)');
                        } else {
                            Log::warning('[Push] FCM to client returned false (completed)');
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[Push] FCM to client failed (non-critical)', ['error' => $e->getMessage()]);
                    }
                } elseif ($status === 'cancelled' && $this->serviceRequest->cancellation_reason === 'auto_expired_worker_accept_window') {
                    try {
                        $result = $firebase->sendToDevice(
                            $client->fcm_token,
                            'Sin confirmación a tiempo',
                            ($this->serviceRequest->worker?->user?->name ?? 'El profesional')
                                . ' no aceptó la solicitud dentro del plazo. Podés volver a intentar o publicar de nuevo.',
                            [
                                'type' => 'request_auto_expired_accept',
                                'request_id' => (string) $this->serviceRequest->id,
                                'cancellation_reason' => 'auto_expired_worker_accept_window',
                            ]
                        );
                        if ($result) {
                            Log::info('[Push] FCM sent to client (auto-expired accept window)');
                        } else {
                            Log::warning('[Push] FCM to client returned false (auto-expired accept window)');
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[Push] FCM to client failed (auto-expired, non-critical)', ['error' => $e->getMessage()]);
                    }
                }
            } else {
                Log::warning('[Push] Client has no FCM token', ['client_id' => $this->serviceRequest->client_id]);
            }

            // Notificar al WORKER según el estado
            $worker = $this->serviceRequest->worker;
            if ($worker && $worker->user && $worker->user->fcm_token) {
                Log::info('[Push] Worker has FCM token', ['worker_id' => $worker->id]);
                
                if ($status === 'cancelled') {
                    try {
                        $isAutoAcceptTimeout = $this->serviceRequest->cancellation_reason === 'auto_expired_worker_accept_window';
                        if ($isAutoAcceptTimeout) {
                            $title = 'Plazo para aceptar vencido';
                            $body = 'La solicitud se cerró sola porque no pulsaste Aceptar a tiempo. Revisa Mis solicitudes si querés retomar otra oportunidad.';
                            $type = 'request_auto_expired_accept';
                        } else {
                            $title = 'Solicitud cancelada';
                            $body = ($this->serviceRequest->client?->name ?? 'Cliente') . ' canceló la solicitud';
                            $type = 'request_cancelled';
                        }
                        $result = $firebase->sendToDevice(
                            $worker->user->fcm_token,
                            $title,
                            $body,
                            [
                                'type' => $type,
                                'request_id' => (string) $this->serviceRequest->id,
                                'cancellation_reason' => (string) ($this->serviceRequest->cancellation_reason ?? ''),
                            ]
                        );
                        if ($result) {
                            Log::info('[Push] FCM sent to worker (cancelled)', ['auto_timeout' => $isAutoAcceptTimeout]);
                        } else {
                            Log::warning('[Push] FCM to worker returned false (cancelled)');
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[Push] FCM to worker failed (non-critical)', ['error' => $e->getMessage()]);
                    }
                }
            } else {
                Log::warning('[Push] Worker has no FCM token', ['worker_id' => $worker?->id]);
            }
        } catch (\Throwable $e) {
            // No bloquear la operación principal si FCM falla completamente
            Log::warning('[Push] ServiceRequestUpdated handle failed (non-critical)', [
                'error' => $e->getMessage(),
                'request_id' => $this->serviceRequest->id ?? null,
            ]);
            // No relanzar la excepción - esto es un servicio no crítico
        }
    }
}
