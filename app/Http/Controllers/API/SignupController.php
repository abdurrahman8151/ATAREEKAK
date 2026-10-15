<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Support\SignupRegistrationHandler;
use App\Http\Controllers\Controller;
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
