<?php

namespace App\Exceptions;

use App\Exceptions\Support\ApiExceptionRenderers;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        (new ApiExceptionRenderers)->register($this);
    }
}
