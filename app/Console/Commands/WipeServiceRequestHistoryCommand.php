<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Borra solicitudes de servicio y datos derivados (chat, disputas, notificaciones in-app, etc.).
 * Conserva: usuarios, workers/tiendas, productos (catalogo externo + analytics), pedidos store_orders.
 */
class WipeServiceRequestHistoryCommand extends Command
{
    protected $signature = 'jobshour:wipe-service-requests
                            {--force : Ejecutar sin confirmación (peligroso en producción)}
                            {--dry-run : Solo mostrar conteos, sin borrar}';

    protected $description = 'Elimina historial de solicitudes de servicio; conserva tiendas, pedidos y datos de productos';

    public function handle(): int
    {
        if (! Schema::hasTable('service_requests')) {
            $this->error('Tabla service_requests no existe.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $counts = [
            'service_requests' => DB::table('service_requests')->count(),
            'messages' => Schema::hasTable('messages') ? DB::table('messages')->count() : 0,
            'notifications' => Schema::hasTable('notifications') ? DB::table('notifications')->count() : 0,
            'search_logs' => Schema::hasTable('search_logs') ? DB::table('search_logs')->count() : 0,
            'profile_views' => Schema::hasTable('profile_views') ? DB::table('profile_views')->count() : 0,
        ];

        $this->table(array_keys($counts), [array_values($counts)]);

        if ($dryRun) {
            $this->warn('[dry-run] No se modificó la base de datos.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('¿Borrar todo el historial de solicitudes (irreversible)?')) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($counts): void {
            if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'service_request_id')) {
                $n = DB::table('payments')->whereNotNull('service_request_id')->delete();
                if ($n > 0) {
                    $this->line("payments (con solicitud): {$n} filas eliminadas.");
                }
            }

            if (Schema::hasTable('notifications')) {
                $n = DB::table('notifications')->delete();
                $this->line("notifications: {$n} filas eliminadas.");
            }

            if (Schema::hasTable('search_logs')) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('TRUNCATE TABLE search_logs RESTART IDENTITY CASCADE');
                    $this->line('search_logs: truncada.');
                } else {
                    $n = DB::table('search_logs')->delete();
                    $this->line("search_logs: {$n} filas eliminadas.");
                }
            }

            if (Schema::hasTable('profile_views')) {
                $n = DB::table('profile_views')->delete();
                $this->line("profile_views: {$n} filas eliminadas.");
            }

            $srQuoteIds = DB::table('service_requests')
                ->whereNotNull('integrated_quote_id')
                ->pluck('integrated_quote_id')
                ->unique()
                ->values();

            $storeQuoteIds = Schema::hasTable('store_orders')
                ? DB::table('store_orders')->whereNotNull('integrated_quote_id')->pluck('integrated_quote_id')->unique()
                : collect();

            $quotesOnlyFromSr = $srQuoteIds->diff($storeQuoteIds);

            if ($quotesOnlyFromSr->isNotEmpty()
                && Schema::hasColumn('service_requests', 'integrated_quote_id')) {
                $n = DB::table('service_requests')
                    ->whereIn('integrated_quote_id', $quotesOnlyFromSr->all())
                    ->update(['integrated_quote_id' => null]);
                $this->line("service_requests: {$n} filas con integrated_quote_id desvinculadas antes de borrar cotizaciones.");
            }

            if ($quotesOnlyFromSr->isNotEmpty() && Schema::hasTable('integrated_quote_items')) {
                $n = DB::table('integrated_quote_items')->whereIn('integrated_quote_id', $quotesOnlyFromSr)->delete();
                $this->line("integrated_quote_items (cotizaciones solo de solicitudes): {$n} filas.");
            }

            if ($quotesOnlyFromSr->isNotEmpty() && Schema::hasTable('integrated_quotes')) {
                $n = DB::table('integrated_quotes')->whereIn('id', $quotesOnlyFromSr)->delete();
                $this->line("integrated_quotes (no usadas por pedidos tienda): {$n} filas.");
            }

            if (Schema::hasTable('reviews') && Schema::hasColumn('reviews', 'service_request_id')) {
                DB::table('reviews')->whereNotNull('service_request_id')->update(['service_request_id' => null]);
                $this->line('reviews: service_request_id anulado donde aplicaba.');
            }

            $deletedSr = DB::table('service_requests')->delete();
            $this->info("service_requests: {$deletedSr} filas eliminadas (mensajes y disputas en cascada si aplica).");

            if (Schema::hasTable('workers') && Schema::hasColumn('workers', 'availability_status')) {
                $u = DB::table('workers')->where('availability_status', 'intermediate')->update(['availability_status' => 'active']);
                if ($u > 0) {
                    $this->line("workers: {$u} pasados de intermediate → active.");
                }
            }
        });

        $this->info('Listo. Tiendas (workers), store_orders y product_analytics_events no fueron borrados.');

        return self::SUCCESS;
    }
}
