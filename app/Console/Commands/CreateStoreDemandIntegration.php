<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\StoreDemandIntegration;
use App\Models\User;
use App\Support\IntegrationIpList;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateStoreDemandIntegration extends Command
{
    protected $signature = 'store-demand:integration
                            {user_id : ID del usuario JobsHours bajo el cual aparecerán las demandas}
                            {name : Nombre de la tienda o integración}
                            {--category= : ID de categoría por defecto (opcional)}
                            {--ips= : IPs del servidor de la tienda permitidas (coma), ej. 203.0.113.10,198.51.100.2 ; vacío = sin restricción}';

    protected $description = 'Genera un token de API para POST /api/v1/integrations/store-demand (el token solo se muestra una vez).';

    public function handle(): int
    {
        $userId = (int) $this->argument('user_id');
        $user = User::query()->find($userId);
        if (! $user) {
            $this->error("No existe el usuario con id {$userId}.");

            return self::FAILURE;
        }

        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('El nombre no puede estar vacío.');

            return self::FAILURE;
        }

        $defaultCategoryId = null;
        if ($this->option('category') !== null && $this->option('category') !== '') {
            $cid = (int) $this->option('category');
            if (! Category::query()->whereKey($cid)->exists()) {
                $this->error("No existe la categoría con id {$cid}.");

                return self::FAILURE;
            }
            $defaultCategoryId = $cid;
        }

        $plain = 'jdh_'.Str::random(40);
        $hash = hash('sha256', $plain);

        $allowedIps = null;
        if ($this->option('ips') !== null && trim((string) $this->option('ips')) !== '') {
            $rawList = array_map('trim', explode(',', (string) $this->option('ips')));
            $err = null;
            $allowedIps = IntegrationIpList::normalizeOrNull($rawList, $err);
            if ($allowedIps === null) {
                $this->error($err ?? 'Lista de IPs inválida.');

                return self::FAILURE;
            }
        }

        StoreDemandIntegration::query()->create([
            'name' => $name,
            'token_hash' => $hash,
            'user_id' => $user->id,
            'default_category_id' => $defaultCategoryId,
            'active' => true,
            'allowed_ips' => $allowedIps,
        ]);

        $this->info('Integración creada. Guardá el token en un gestor seguro (no se puede recuperar).');
        $this->newLine();
        $this->line('Token (Bearer):');
        $this->warn($plain);
        $this->newLine();
        $this->line('Endpoint: POST {APP_URL}/api/v1/integrations/store-demand');
        $this->line('Header: Authorization: Bearer <token>');
        $this->line('Body JSON mínimo: external_order_id, description, lat, lng'
            .($defaultCategoryId ? '' : ', category_id'));
        if ($allowedIps !== null) {
            $this->newLine();
            $this->comment('Lista blanca de IP activa: solo esas IPs pueden llamar al endpoint.');
        }

        return self::SUCCESS;
    }
}
