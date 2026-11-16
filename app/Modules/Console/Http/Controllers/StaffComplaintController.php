<?php

namespace App\Modules\Console\Http\Controllers;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Controller;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Console\Services\StaffComplaintService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * StaffComplaintController
 *
 * UC-ADM-06: Support agent views and responds to user complaints.
 *
 * Routes (all behind `staff` middleware):
 *   GET   /api/staff/complaints                  → index()
 *   GET   /api/staff/complaints/{id}             → show()   ← auto pending→in_review
 *   PATCH /api/staff/complaints/{id}/respond     → respond()
 */
final class StaffComplaintController extends Controller
{
    public function __construct(
        private readonly StaffComplaintService $complaintService,
        private readonly NotificationPort $notificationService,
    ) {}

    // ── GET /api/staff/complaints ─────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:pending,in_review,resolved,closed',
            'type' => 'sometimes|in:trip_safety,driver_behavior,passenger_behavior,ride_cancellation,financial_issue,account_issue,technical_issue,no_show,other',
            'date' => 'sometimes|in:last_7_days,last_30_days',
            'user_id' => 'sometimes|integer|exists:users,id',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $paginator = $this->complaintService->listAll(
            status: $request->get('status'),
            type: $request->get('type'),
            date: $request->get('date'),
            userId: $request->integer('user_id') ?: null,
            perPage: (int) $request->get('per_page', 15),
            page: (int) $request->get('page', 1),
        );

        // Stage 2 C2: ONE batched assignee lookup for the page, replacing the `assignedAgent` eager load.
        $this->complaintService->preloadAgents($paginator->getCollection());
        // Stage 2 C3: and ONE batched complainant lookup from Identity, replacing the `user` eager load.
        $this->complaintService->preloadUsers($paginator->getCollection());

        return response()->json([
            'status' => 'success',
            'data' => $paginator->getCollection()
                ->map(fn ($c) => $this->complaintService->format($c))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            // Quick counts for the tab badges in the UI
            'counts' => $this->complaintService->statusCounts(),
        ]);
    }

    public function escalate(int $complaintId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:1000',
        ], [
            'reason.required' => 'Please provide a reason for escalation.',
            'reason.min' => 'Reason must be at least 10 characters.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $agent = $request->attributes->get('staffEmployee');
            $complaint = $this->complaintService->escalate(
                complaintId: $complaintId,
                reason: $request->input('reason'),
                agent: $agent,
            );

            // in_review decrements, 'all' shifts — badge counts are now stale
            Cache::forget('staff.complaint-counts');

            return response()->json([
                'status' => 'success',
                'message' => 'Complaint escalated to admin successfully.',
                'data' => $this->complaintService->format($complaint),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Complaint not found.',
            ], 404);
        } catch (\DomainException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // ── GET /api/staff/complaints/{id} ───────────────────────────────────────
    // Opening a PENDING complaint automatically moves it to IN_REVIEW
    // and assigns it to the authenticated agent.
    public function show(int $complaintId, Request $request): JsonResponse
    {
        try {
            $agent = $request->attributes->get('staffEmployee');
            $complaint = $this->complaintService->openComplaint($complaintId, $agent);

            // pending→in_review transition changes both badge counts
            Cache::forget('staff.complaint-counts');

            return response()->json([
                'status' => 'success',
                'data' => $this->complaintService->format($complaint),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Complaint not found.',
            ], 404);
        }
    }

    // ── PATCH /api/staff/complaints/{id}/respond ──────────────────────────────
    public function respond(int $complaintId, Request $request): JsonResponse
    {
        $validationError = $this->validateRespondRequest($request);
        if ($validationError) {
            return $validationError;
        }

        try {
            $agent = $request->attributes->get('staffEmployee');
            $newStatus = ComplaintStatus::from($request->input('status'));

            $complaint = $this->complaintService->respond(
                complaintId: $complaintId,
                resolutionNotes: $request->input('resolution_notes'),
                newStatus: $newStatus,
                agent: $agent,
            );

            $this->complaintService->notifyStatusChange($complaint, $complaintId, $newStatus);

            // Status transition changes resolved/closed/in_review badge counts
            Cache::forget('staff.complaint-counts');

            return response()->json([
                'status' => 'success',
                'message' => "Complaint marked as {$newStatus->label()} and user has been notified.",
                'data' => $this->complaintService->format($complaint),
            ]);
        } catch (ModelNotFoundException) {
            return response()->json([
                'status' => 'error',
                'message' => 'Complaint not found.',
            ], 404);
        } catch (\DomainException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Validation for respond(). Returns the 422 response, or null when the input is valid.
     */
    private function validateRespondRequest(Request $request): ?JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'resolution_notes' => 'required|string|min:10|max:3000',
            'status' => 'required|in:in_review,resolved,closed',
        ], [
            'resolution_notes.required' => 'A response message is required.',
            'resolution_notes.min' => 'Response must be at least 10 characters.',
            'status.required' => 'Please specify the new complaint status.',
            'status.in' => 'Status must be one of: in_review, resolved, closed.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        return null;
    }
}
