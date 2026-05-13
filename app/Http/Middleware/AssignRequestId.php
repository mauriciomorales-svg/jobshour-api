<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asigna un ID de correlación por request (header o UUID) para logs y respuestas API.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $rid = $request->header('X-Request-Id');
        if (! is_string($rid) || trim($rid) === '') {
            $rid = (string) Str::uuid();
        }

        $request->attributes->set('request_id', $rid);
        Log::withContext(['request_id' => $rid]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $rid);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        Log::withoutContext();
    }
}
