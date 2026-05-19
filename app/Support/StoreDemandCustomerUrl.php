<?php

namespace App\Support;

class StoreDemandCustomerUrl
{
    public static function forRequest(int $requestId, ?string $source = 'store_demand'): string
    {
        $base = rtrim((string) config('app.frontend_url', 'https://jobshours.com'), '/');
        $query = [
            'request_id' => (string) $requestId,
            'open_chat' => '1',
        ];
        if ($source !== null && $source !== '') {
            $query['source'] = $source;
        }

        return $base.'/?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
