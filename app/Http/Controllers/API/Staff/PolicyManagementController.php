<?php

namespace App\Http\Controllers\API\Staff;

use App\Enums\PolicyType;
use App\Http\Controllers\Controller;
use App\Services\PolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * system_admin-only management of the privacy/cancellation policy text and
 * its contact metadata — the dashboard Settings page. Route-level access is
 * enforced by StaffJwtMiddleware (`staff:system_admin`).
 */
final class PolicyManagementController extends Controller
{
    public function __construct(
        private readonly PolicyService $policyService,
    ) {}

    // ── GET /api/admin/policies ─────────────────────────────────────────────

    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->policyService->getPayload(),
        ]);
    }

    // ── PUT /api/admin/policies/{type} ──────────────────────────────────────

    public function update(string $type, Request $request): JsonResponse
    {
        if (!in_array($type, PolicyType::values(), true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid policy type.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'last_updated_label' => 'nullable|string|max:100',
            'sections' => 'required|array|min:1',
            'sections.*.title' => 'required|string|max:255',
            'sections.*.intro' => 'nullable|string',
            'sections.*.points' => 'nullable|array',
            'sections.*.points.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $policy = $this->policyService->updatePolicy(
                $type,
                $validator->validated(),
                $request->attributes->get('staffEmployee')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Policy updated successfully.',
                'data' => [
                    'last_updated_label' => $policy->last_updated_label,
                    'sections' => $policy->sections,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Policy update failed', ['error' => $e->getMessage(), 'class' => get_class($e)]);
            return $this->serverError();
        }
    }

    // ── PUT /api/admin/policies/faq ─────────────────────────────────────────

    public function updateFaq(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'groups' => 'required|array|min:1',
            'groups.*.title' => 'required|string|max:255',
            'groups.*.icon' => 'required|string|max:100',
            'groups.*.entries' => 'required|array|min:1',
            'groups.*.entries.*.question' => 'required|string|max:500',
            'groups.*.entries.*.answer' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $faq = $this->policyService->updateFaq(
                $validator->validated(),
                $request->attributes->get('staffEmployee')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'FAQ updated successfully.',
                'data' => [
                    'groups' => $faq->sections,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('FAQ update failed', ['error' => $e->getMessage(), 'class' => get_class($e)]);
            return $this->serverError();
        }
    }

    // ── PUT /api/admin/policies/settings ────────────────────────────────────

    public function updateSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company' => 'required|string|max:255',
            'app_name' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'contact_phone' => 'required|string|max:50',
            'contact_address' => 'required|string|max:255',
            'consent_label' => 'required|string|max:255',
            'platform_profit_percentage' => 'required|numeric|min:0|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->policyService->updateSettings(
                $validator->validated(),
                $request->attributes->get('staffEmployee')
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Policy settings updated successfully.',
                'data' => $this->policyService->getPayload()['settings'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Policy settings update failed', ['error' => $e->getMessage(), 'class' => get_class($e)]);
            return $this->serverError();
        }
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
