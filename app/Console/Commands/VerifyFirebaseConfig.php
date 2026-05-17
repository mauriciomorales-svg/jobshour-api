<?php

namespace App\Console\Commands;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Console\Command;

class VerifyFirebaseConfig extends Command
{
    protected $signature = 'firebase:verify';

    protected $description = 'Verificar configuración de Firebase';

    public function handle()
    {
        $this->info('🔍 Verificando configuración de Firebase...');
        $this->newLine();

        $credentialsFile = config('firebase.credentials.file');
        if (is_string($credentialsFile) && $credentialsFile !== '' && ! str_starts_with($credentialsFile, '/') && ! preg_match('/^[A-Za-z]:\\\\/', $credentialsFile)) {
            $credentialsFile = base_path($credentialsFile);
        }

        $this->info('1. Archivo de credenciales:');
        $this->info('   Ruta: '.$credentialsFile);

        if (! is_string($credentialsFile) || ! file_exists($credentialsFile)) {
            $this->error('   ❌ No existe el archivo de credenciales');

            return 1;
        }

        $credentials = json_decode(file_get_contents($credentialsFile), true);
        if (! is_array($credentials)) {
            $this->error('   ❌ Archivo JSON inválido');

            return 1;
        }

        $this->info('   ✅ Archivo de credenciales válido');
        $this->info('   📧 Client email: '.($credentials['client_email'] ?? '—'));

        $projectId = config('firebase.project_id');
        $this->newLine();
        $this->info('2. Project ID: '.$projectId);

        $this->newLine();
        $this->info('3. Probando autenticación con Google (google/auth)...');

        try {
            $sa = new ServiceAccountCredentials(
                'https://www.googleapis.com/auth/firebase.messaging',
                $credentialsFile,
            );
            $token = $sa->fetchAuthToken();
            $accessToken = $token['access_token'] ?? null;

            if (! is_string($accessToken) || $accessToken === '') {
                $this->error('   ❌ No se obtuvo access_token');
                $this->line('   Respuesta: '.json_encode($token));

                return 1;
            }

            $expiresIn = $token['expires_in'] ?? 3600;
            $this->info('   ✅ Autenticación exitosa');
            $this->info("   📝 Access token obtenido (expira en {$expiresIn}s)");
        } catch (\Throwable $e) {
            $this->error('   ❌ Error de autenticación: '.$e->getMessage());

            return 1;
        }

        $webApiKey = config('firebase.web_api_key');
        $this->newLine();
        $this->info('4. FIREBASE_WEB_API_KEY: '.(is_string($webApiKey) && $webApiKey !== '' ? 'configurada' : 'falta'));

        $this->newLine();
        $this->info('✅ Firebase configurado correctamente');
        $this->info('Para probar una notificación: php scripts/send-test-push-user.php <USER_ID>');

        return 0;
    }
}
