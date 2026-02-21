<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public string $code;

    public function __construct(User $user, string $code)
    {
        $this->user = $user;
        $this->code = $code;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Código de verificación - JobsHour',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "
                <div style='font-family: Arial, sans-serif; max-width: 400px; margin: 0 auto; padding: 20px;'>
                    <h2 style='color: #10b981; text-align: center;'>JobsHour</h2>
                    <p>Hola {$this->user->name},</p>
                    <p>Tu código de verificación es:</p>
                    <div style='background: #f3f4f6; padding: 20px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 8px; border-radius: 8px; margin: 20px 0;'>
                        {$this->code}
                    </div>
                    <p style='color: #6b7280; font-size: 14px;'>Este código expira en 30 minutos.</p>
                    <p style='color: #6b7280; font-size: 12px; margin-top: 30px;'>Si no solicitaste este código, ignora este mensaje.</p>
                </div>
            ",
        );
    }
}
