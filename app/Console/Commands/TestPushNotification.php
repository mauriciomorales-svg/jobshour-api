<?php

namespace App\Console\Commands;

use App\Services\FirebaseService;
use Illuminate\Console\Command;

class TestPushNotification extends Command
{
    protected $signature = 'firebase:test-push {token} {--title=Test} {--body=Mensaje de prueba}';
    protected $description = 'Enviar notificación push de prueba';

    public function handle()
    {
        $token = $this->argument('token');
        $title = $this->option('title');
        $body = $this->option('body');

        $this->info("Enviando notificación de prueba...");
        $this->info("Token: {$token}");
        $this->info("Título: {$title}");
        $this->info("Mensaje: {$body}");

        $firebase = new FirebaseService();
        $result = $firebase->sendToDevice($token, $title, $body, ['type' => 'test']);

        if ($result) {
            $this->info('✅ Notificación enviada exitosamente');
            return 0;
        } else {
            $this->error('❌ Error al enviar notificación');
            return 1;
        }
    }
}
