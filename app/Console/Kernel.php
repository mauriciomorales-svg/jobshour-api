<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule($schedule): void
    {
        // Tareas programadas: definidas en bootstrap/app.php ->withSchedule() (Laravel 11).
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
}
