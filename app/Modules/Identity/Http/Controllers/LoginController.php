<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Handlers\LoginHandler;
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
