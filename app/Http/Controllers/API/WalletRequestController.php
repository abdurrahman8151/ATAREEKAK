<?php

namespace App\Http\Controllers\API;

use App\Exceptions\Domain\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\WalletChargeRequest;
use App\Http\Requests\Wallet\WalletStoreRequest;
use App\Http\Requests\Wallet\WalletWithdrawRequest;
use App\Services\Wallet\WalletRequestService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletRequestController extends Controller
{
    public function __construct(
        private readonly WalletRequestService $service,
    ) {}

    public function requestCharge(WalletChargeRequest $request): JsonResponse
    {
        try {
            $walletRequest = $this->service->requestCharge(
                $request->user(),
                (float) $request->validated('amount'),
                $request->validated('notes')
            );

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Charge request submitted. The admin will review it shortly.',
                'data' => $this->service->format($walletRequest),
            ], 201);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        }
    }

    public function requestWithdraw(WalletWithdrawRequest $request): JsonResponse
    {
        try {
            $walletRequest = $this->service->requestWithdraw(
                $request->user(),
                (float) $request->validated('amount'),
                $request->validated('notes')
            );

            return response()->json([
                'success' => true,
                'status' => 'success',
                'message' => 'Withdraw request submitted. The admin will process it shortly.',
                'data' => $this->service->format($walletRequest),
            ], 201);
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'status' => 'error',
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        }
    }

    public function store(WalletStoreRequest $request): JsonResponse
    {
        try {
            $walletRequest = $this->service->create(
                $request->user(),
                $request->validated('type'),
                (float) $request->validated('amount'),
                $request->notes()
            );

            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => 'Wallet request submitted.',
                'data' => $this->service->format($walletRequest),
            ], 201);
        } catch (DomainException $e) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        }
    }

    public function myRequests(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => 'success',
            'data' => $this->service->listForUser($request->user()->id),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $walletRequest = $this->service->getForUser($request->user()->id, $id);

            return response()->json([
                'status' => 'success',
                'success' => true,
                'data' => $this->service->format($walletRequest),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'Request not found.',
            ], 404);
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $walletRequest = $this->service->cancelForUser($request->user()->id, $id);

            return response()->json([
                'status' => 'success',
                'success' => true,
                'message' => 'Request cancelled.',
                'data' => $this->service->format($walletRequest),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => 'Request not found.',
            ], 404);
        } catch (DomainException $e) {
            return response()->json([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        }
    }
}
