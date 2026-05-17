<?php

namespace App\Console\Commands;

use App\Models\StoreDemandIntegration;
use App\Support\IntegrationIpList;
use Illuminate\Console\Command;

class SetStoreDemandIntegrationIps extends Command
{
    protected $signature = 'store-demand:integration-ips
                            {integration_id : ID en store_demand_integrations}
                            {ips? : Lista separada por comas}
                            {--clear : Quitar restricción por IP (cualquier IP con token válido)}';

    protected $description = 'Define o limpia la lista blanca de IPs para POST /api/v1/integrations/store-demand.';

    public function handle(): int
    {
        $id = (int) $this->argument('integration_id');
        $row = StoreDemandIntegration::query()->find($id);
        if (! $row) {
            $this->error("No existe la integración con id {$id}.");

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $row->update(['allowed_ips' => null]);
            $this->info("Integración #{$id}: restricción por IP desactivada (cualquier IP con token válido).");

            return self::SUCCESS;
        }

        $raw = $this->argument('ips');
        if ($raw === null || trim((string) $raw) === '') {
            $this->error('Pasá una lista de IPs (coma) o usá --clear para quitar la restricción.');

            return self::FAILURE;
        }

        $rawList = array_map('trim', explode(',', (string) $raw));
        $err = null;
        $list = IntegrationIpList::normalizeOrNull($rawList, $err);
        if ($list === null) {
            $this->error($err ?? 'Lista de IPs inválida.');

            return self::FAILURE;
        }
        $row->update(['allowed_ips' => $list]);
        $this->info("Integración #{$id}: lista blanca actualizada (".count($list).' IP/s).');

        return self::SUCCESS;
    }
}
