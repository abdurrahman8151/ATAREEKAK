<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PolicyService;
use Illuminate\Http\JsonResponse;

/**
 * Public read of the privacy/cancellation policy text and its contact
 * metadata — no auth required, so the mobile app can render it before
 * login/signup. Editing happens via `Staff\PolicyManagementController`.
 */
final class PolicyController extends Controller
{
    public function __construct(
        private readonly PolicyService $policyService,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->policyService->getPayload(),
        ]);
    }
}
