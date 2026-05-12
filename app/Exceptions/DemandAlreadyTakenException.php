<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

class DemandAlreadyTakenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta demanda ya fue tomada por otro trabajador');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $this->getMessage(),
        ], 409);
    }
}
