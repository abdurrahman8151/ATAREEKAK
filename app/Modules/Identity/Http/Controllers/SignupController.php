<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Handlers\SignupRegistrationHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignupController extends Controller
{
    public function __construct(
        private readonly SignupRegistrationHandler $registration,
    ) {}

    public function register(Request $request): JsonResponse
    {
        return $this->registration->register($request);
    }
}
