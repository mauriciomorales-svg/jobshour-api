<?php

namespace App\Support;

/**
 * Normaliza y valida IPs para lista blanca de integraciones (IPv4 o IPv6).
 */
final class IntegrationIpList
{
    /**
     * @param  array<int, string>  $ips
     * @return array<int, string>|null  null si alguna entrada no es IP válida
     */
    public static function normalizeOrNull(array $ips, ?string &$errorMessage = null): ?array
    {
        $out = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '') {
                continue;
            }
            if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                $errorMessage = "IP inválida: {$ip}";

                return null;
            }
            $out[] = $ip;
        }

        return array_values(array_unique($out));
    }
}
