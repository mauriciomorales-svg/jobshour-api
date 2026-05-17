<?php

namespace App\Console\Commands;

use App\Models\StoreDemandIntegration;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RotateStoreDemandIntegration extends Command
{
    protected $signature = 'store-demand:integration-rotate
                            {integration_id : ID en store_demand_integrations}';

    protected $description = 'Genera un nuevo token para la integración (el anterior deja de funcionar). El nuevo token se muestra una sola vez.';

    public function handle(): int
    {
        $id = (int) $this->argument('integration_id');
        $row = StoreDemandIntegration::query()->find($id);
        if (! $row) {
            $this->error("No existe la integración con id {$id}.");

            return self::FAILURE;
        }

        $plain = 'jdh_'.Str::random(40);
        $row->update([
            'token_hash' => hash('sha256', $plain),
        ]);

        $this->info("Integración #{$id} ({$row->name}): token rotado.");
        $this->newLine();
        $this->line('Nuevo token (Bearer):');
        $this->warn($plain);
        $this->newLine();
        $this->comment('Actualizá el secreto en el servidor de la tienda; el token anterior ya no es válido.');

        return self::SUCCESS;
    }
}
