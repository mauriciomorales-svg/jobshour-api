<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [];
    
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            $status = 500;
            if (method_exists($exception, 'getStatusCode')) {
                $status = $exception->getStatusCode();
            }
            
            return response()->json([
                'error' => true,
                'message' => $exception->getMessage(),
            ], $status);
        }

        return parent::render($request, $exception);
    }
}
