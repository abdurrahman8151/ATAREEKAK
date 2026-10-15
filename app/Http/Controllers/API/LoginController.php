<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\Support\LoginHandler;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function __construct(
        private readonly LoginHandler $login,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->login->__invoke($request);
    }
}
