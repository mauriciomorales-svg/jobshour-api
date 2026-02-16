<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class VerifyFirebaseConfig extends Command
{
    protected $signature = 'firebase:verify';
    protected $description = 'Verificar configuración de Firebase';

    public function handle()
    {
        $this->info('🔍 Verificando configuración de Firebase...\n');

        // 1. Verificar archivo de credenciales
        $credentialsFile = config('firebase.credentials.file');
        $this->info('1. Archivo de credenciales:');
        $this->info('   Ruta: ' . $credentialsFile);
        
        if (!file_exists($credentialsFile)) {
            $this->error('   ❌ No existe el archivo de credenciales');
            return 1;
        }
        
        $credentials = json_decode(file_get_contents($credentialsFile), true);
        if (!$credentials) {
            $this->error('   ❌ Archivo JSON inválido');
            return 1;
        }
        
        $this->info('   ✅ Archivo de credenciales válido');
        $this->info('   📧 Client email: ' . $credentials['client_email']);

        // 2. Verificar Project ID
        $projectId = config('firebase.project_id');
        $this->info('\n2. Project ID: ' . $projectId);

        // 3. Probar autenticación
        $this->info('\n3. Probando autenticación con Google...');
        
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $time = time();
        $payload = json_encode([
            'iss' => $credentials['client_email'],
            'sub' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $time,
            'exp' => $time + 3600,
        ]);

        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = '';
        if (!openssl_sign($base64Header . '.' . $base64Payload, $signature, $credentials['private_key'], 'SHA256')) {
            $this->error('   ❌ Error al firmar JWT');
            return 1;
        }
        
        $jwt = $base64Header . '.' . $base64Payload . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if ($response->successful()) {
            $this->info('   ✅ Autenticación exitosa');
            $this->info('   📝 Access token obtenido (válido por 1 hora)');
        } else {
            $this->error('   ❌ Error de autenticación: ' . $response->body());
            return 1;
        }

        $this->info('\n✅ Firebase configurado correctamente');
        $this->info('\nPara probar una notificación:');
        $this->info('  php artisan firebase:test-push <FCM_TOKEN>');

        return 0;
    }
}
