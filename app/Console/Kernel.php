<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule($schedule): void
    {
        // Verificar trabajos inactivos cada 5 minutos
        $schedule->command('jobs:check-inactive')
                 ->everyFiveMinutes()
                 ->withoutOverlapping()
                 ->runInBackground();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
