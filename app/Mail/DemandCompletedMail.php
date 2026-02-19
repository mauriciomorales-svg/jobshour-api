<?php

namespace App\Mail;

use App\Models\ServiceRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DemandCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ServiceRequest $serviceRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '🎉 Servicio completado - ¡Califica al socio!');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.demand-completed');
    }
}
