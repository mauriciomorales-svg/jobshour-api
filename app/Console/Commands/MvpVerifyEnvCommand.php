<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba variables y servicios mínimos para un lanzamiento MVP.
 *
 * Uso: php artisan mvp:verify-env
 *      php artisan mvp:verify-env --strict   # falla también con advertencias (CI)
 */
class MvpVerifyEnvCommand extends Command
{
    protected $signature = 'mvp:verify-env {--strict : Salir con error si hay advertencias (p. ej. mail=log en prod)}';

    protected $description = 'Verifica APP_KEY, BD, FRONTEND_URL, Mercado Pago y correo para MVP';

    public function handle(): int
    {
        $production = app()->environment('production');
        $errors = 0;
        $warnings = 0;

        $this->info('=== Verificación entorno MVP (API) ===');
        $this->newLine();

        $key = (string) config('app.key', '');
        if ($key === '' || strlen($key) < 32) {
            $this->error('APP_KEY: vacío o demasiado corto.');
            ++$errors;
        } else {
            $this->line('APP_KEY: <fg=green>ok</>');
        }

        try {
            DB::select('SELECT 1');
            $this->line('Base de datos: <fg=green>conexión ok</>');
        } catch (\Throwable $e) {
            $this->error('Base de datos: fallo — ' . $e->getMessage());
            ++$errors;
        }

        $fe = rtrim((string) config('app.frontend_url', ''), '/');
        if ($fe === '') {
            $this->error('FRONTEND_URL: vacío (necesario para back_urls MP y correos de tienda).');
            ++$errors;
        } else {
            $this->line('FRONTEND_URL: <fg=green>' . $fe . '</>');
            if ($production && (str_contains($fe, 'localhost') || str_contains($fe, '127.0.0.1'))) {
                $this->warn('FRONTEND_URL apunta a localhost en producción.');
                ++$warnings;
            }
        }

        $mp = trim((string) config('services.mercadopago.access_token', ''));
        if ($mp === '') {
            $this->error('Mercado Pago: MP_ACCESS_TOKEN / MERCADOPAGO_ACCESS_TOKEN vacío.');
            ++$errors;
        } else {
            $this->line('Mercado Pago token: <fg=green>definido</> (' . strlen($mp) . ' caracteres)');
        }

        $mailer = strtolower(trim((string) env('MAIL_MAILER', 'log')));
        $this->line('MAIL_MAILER: ' . ($mailer !== '' ? $mailer : '(vacío, Laravel usará default)'));
        if ($production && $mailer === 'log') {
            $this->warn('En producción MAIL_MAILER=log: los correos (p. ej. pedido tienda pagado) solo van a logs.');
            ++$warnings;
        }
        if ($mailer === 'smtp' && trim((string) env('MAIL_HOST', '')) === '') {
            $this->warn('MAIL_MAILER=smtp pero MAIL_HOST vacío.');
            ++$warnings;
        }

        $from = trim((string) env('MAIL_FROM_ADDRESS', ''));
        if ($from === '') {
            $this->warn('MAIL_FROM_ADDRESS vacío.');
            ++$warnings;
        } else {
            $this->line('MAIL_FROM_ADDRESS: <fg=green>' . $from . '</>');
        }

        $support = trim((string) env('SUPPORT_EMAIL', ''));
        if ($support === '') {
            $this->line('SUPPORT_EMAIL: (vacío, se usa contacto@jobshour.cl en textos de mail)');
        } else {
            $this->line('SUPPORT_EMAIL: <fg=green>' . $support . '</>');
        }

        $this->newLine();
        if ($errors > 0) {
            $this->error('Resultado: FALLO (' . $errors . ' error(es)). Corrige .env y vuelve a ejecutar.');

            return self::FAILURE;
        }
        if ($warnings > 0 && $this->option('strict')) {
            $this->warn('Resultado: advertencias con --strict → salida de error (' . $warnings . ').');

            return self::FAILURE;
        }
        if ($warnings > 0) {
            $this->warn('Resultado: OK con ' . $warnings . ' advertencia(s). Usa --strict en CI para fallar.');
        } else {
            $this->info('Resultado: OK — listo para pruebas de lanzamiento.');
        }

        $this->newLine();
        $this->line('Siguiente paso: probar <fg=cyan>GET /api/v1/health/ping</> y un pago de tienda en sandbox.');

        return self::SUCCESS;
    }
}
