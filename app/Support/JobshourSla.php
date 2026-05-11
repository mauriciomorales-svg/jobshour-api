<?php

namespace App\Support;

use Carbon\CarbonInterface;

class JobshourSla
{
    /** Mínimo 15 s para no romper entornos de prueba con valores absurdos. */
    public static function mapTakeAcceptSeconds(): int
    {
        return max(15, (int) config('jobshour_sla.map_take_worker_accept_seconds', 90));
    }

    public static function mapTakeExpiresAt(): CarbonInterface
    {
        return now()->addSeconds(self::mapTakeAcceptSeconds());
    }

    public static function directBookingAcceptMinutes(): int
    {
        return max(1, (int) config('jobshour_sla.direct_booking_worker_accept_minutes', 5));
    }

    public static function directBookingAcceptExpiresAt(): CarbonInterface
    {
        return now()->addMinutes(self::directBookingAcceptMinutes());
    }

    public static function describeSeconds(int $seconds): string
    {
        $seconds = max(1, $seconds);
        if ($seconds < 60) {
            return $seconds === 1 ? '1 segundo' : "{$seconds} segundos";
        }
        $m = intdiv($seconds, 60);
        $rem = $seconds % 60;
        if ($rem === 0) {
            return $m === 1 ? '1 minuto' : "{$m} minutos";
        }

        return "{$m} min {$rem} s";
    }
}
