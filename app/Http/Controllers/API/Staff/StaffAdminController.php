<?php

namespace App\Http\Controllers\API\Staff;

use App\Enums\ComplaintStatus;
use App\Http\Controllers\Controller;
use App\Interfaces\VerificationRepositoryInterface;
use App\Models\User;
use App\Services\Staff\StaffComplaintService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * StaffAdminController
 *
 * Admin + System Admin only routes.
 * Protected by: middleware('staff:admin,system_admin')
 *
 * ── UC-ADM-10 : Review Verification Requests ──────────────────────────────
 *   GET   /api/staff/verifications/pending       → pendingVerifications()
 *
 * ── UC-ADM-11 : Approve / Reject Verification ─────────────────────────────
 *   POST  /api/staff/verifications/{userId}/approve  → approveVerification()
 *   POST  /api/staff/verifications/{userId}/reject   → rejectVerification()
 *
 * ── Escalated Complaints ──────────────────────────────────────────────────
 *   GET   /api/staff/escalated-complaints            → escalatedComplaints()
 *   PATCH /api/staff/escalated-complaints/{id}/resolve → resolveEscalated()
 */
final class StaffAdminController extends Controller
{
    public function __construct(
        private readonly StaffComplaintService         $complaintService,
        private readonly VerificationRepositoryInterface $verificationRepo,
    ) {}

    // =========================================================================
    // UC-ADM-10 – PENDING VERIFICATIONS LIST
    // =========================================================================

    public function pendingVerifications(): JsonResponse
    {
        try {
            // Heavy eager load (photos + profiles for every pending user).
            // Busted explicitly by approveVerification() and rejectVerification().
            // New submissions from the user side self-heal within TTL.
            $pending = Cache::remember('staff.pending-verifications', now()->addMinutes(2), function () {
                return User::with(['photos', 'profile'])
                    ->where('verification_status', 'pending')
                    ->orderByDesc('updated_at')
                    ->get()
                    ->map(function (User $u) {
                        $docTypes = $u->photos->pluck('type')->toArray();
                        $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

                        return [
                            'user_id'      => $u->id,
                            'name'         => trim("{$u->first_name} {$u->last_name}"),
                            'email'        => $u->email,
                            'gender'       => $u->gender,
                            'address'      => $u->address,
                            'type'         => $isDriver ? 'driver' : 'passenger',
                            'profile_photo'=> $u->profile?->profile_photo
                                ? asset('storage/' . $u->profile->profile_photo)
                                : null,
                            'documents'    => $u->photos->map(fn ($p) => [
                                'type' => $p->type,
                                'url'  => asset('storage/' . $p->path),
                            ])->values()->all(),
                            'submitted_at' => $u->updated_at->toIso8601String(),
                        ];
                    })->values()->all();
            });

            return response()->json([
                'status' => 'success',
                'total'  => count($pending),
                'data'   => $pending,
            ]);

        } catch (\Exception $e) {
            Log::error('StaffAdmin: pendingVerifications failed', ['error' => $e->getMessage()]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // UC-ADM-11 – APPROVE VERIFICATION
    // =========================================================================

    /**
     * POST /api/staff/verifications/{userId}/approve
     *
     * Body (required):
     *   national_id  string  The national ID number read from the submitted documents.
     *                        Must be unique across all verified users.
     *
     * Only admin and system_admin may call this endpoint.
     * Support agents are blocked at the route/middleware level.
     */
    public function approveVerification(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'national_id' => 'required|digits:10',
        ], [
            'national_id.required' => 'The national ID number is required to approve verification.',
            'national_id.digits'   => 'The national ID number must be exactly 10 digits.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $nationalId = trim($request->input('national_id'));

        // Check uniqueness: is this national ID already linked to another account?
        $duplicate = User::where('national_id', $nationalId)
            ->where('id', '!=', $userId)
            ->first();

        if ($duplicate) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This national ID is already linked to another verified account. Verification blocked.',
                'data'    => [
                    'conflicting_user_id' => $duplicate->id,
                ],
            ], 422);
        }

        try {
            $user     = User::with('photos')->findOrFail($userId);
            $docTypes = $user->photos->pluck('type')->toArray();
            $isDriver = in_array('license', $docTypes) || in_array('mechanic_card', $docTypes);

            $verified = $isDriver
                ? $this->verificationRepo->verifyDriver($userId)
                : $this->verificationRepo->verifyPassenger($userId);

            // Save national ID — cannot be changed through normal flows after this point
            $verified->national_id = $nationalId;
            $verified->save();

            // Fire the broadcast event so the user's app updates in real time
            event(new \App\Events\UserVerified(
                $verified,
                $isDriver ? 'driver' : 'passenger'
            ));

            // User leaves the pending list; their staff profile now shows new verification status
            Cache::forget('staff.pending-verifications');
            Cache::forget("staff.user-profile.{$userId}");

            return response()->json([
                'status'  => 'success',
                'message' => ($isDriver ? 'Driver' : 'Passenger') . ' verification approved.',
                'data'    => [
                    'user_id'               => $verified->id,
                    'national_id'           => $verified->national_id,
                    'verification_status'   => $verified->verification_status,
                    'is_verified_driver'    => (bool) $verified->is_verified_driver,
                    'is_verified_passenger' => (bool) $verified->is_verified_passenger,
                ],
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('StaffAdmin: approveVerification failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    // =========================================================================
    // UC-ADM-11 – REJECT VERIFICATION
    // =========================================================================

    public function rejectVerification(int $userId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = User::findOrFail($userId);

            if ($user->verification_status !== 'pending') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'User does not have a pending verification request.',
                ], 422);
            }

            $user->update([
                'verification_status'   => 'rejected',
                'is_verified_passenger' => false,
                'is_verified_driver'    => false,
            ]);

            try {
                app(\App\Services\NotificationService::class)->createNotification(
                    $user,
                    'verification_rejected',
                    'طلب التوثيق مرفوض',
                    'تم رفض طلب توثيق حسابك.'
                    . ($request->input('reason') ? ' السبب: ' . $request->input('reason') : ' يمكنك إعادة التقديم بعد تصحيح البيانات.'),
                    ['user_id' => $user->id],
                    'high',
                    'system'
                );
            } catch (\Throwable) {}

            // User leaves the pending list; their staff profile now shows new verification status
            Cache::forget('staff.pending-verifications');
            Cache::forget("staff.user-profile.{$userId}");

            return response()->json([
                'status'  => 'success',
                'message' => 'Verification rejected. User has been notified.',
                'data'    => [
                    'user_id'             => $user->id,
                    'verification_status' => $user->verification_status,
                ],
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'User not found.'], 404);
        } catch (\Exception $e) {
            Log::error('StaffAdmin: rejectVerification failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // ESCALATED COMPLAINTS – LIST
    // =========================================================================

    public function escalatedComplaints(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status'   => 'sometimes|in:escalated,resolved,closed',
            'type'     => 'sometimes|in:trip_safety,driver_behavior,passenger_behavior,ride_cancellation,financial_issue,account_issue,technical_issue,no_show,other',
            'date'     => 'sometimes|in:last_7_days,last_30_days',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $paginator = $this->complaintService->listEscalated(
            status:  $request->get('status'),
            type:    $request->get('type'),
            date:    $request->get('date'),
            perPage: (int) $request->get('per_page', 15),
            page:    (int) $request->get('page', 1),
        );

        return response()->json([
            'status' => 'success',
            'data'   => $paginator->getCollection()
                ->map(fn ($c) => $this->complaintService->format($c))
                ->values(),
            'meta'   => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
            'counts' => $this->escalatedStatusCounts(),
        ]);
    }

    // =========================================================================
    // ESCALATED COMPLAINTS – RESOLVE
    // =========================================================================

    public function resolveEscalated(int $complaintId, Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'resolution_notes' => 'required|string|min:10|max:3000',
            'status'           => 'required|in:resolved,closed',
        ], [
            'resolution_notes.required' => 'A resolution message is required.',
            'resolution_notes.min'      => 'Resolution must be at least 10 characters.',
            'status.in'                 => 'Status must be resolved or closed.',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        try {
            $admin     = $request->attributes->get('staffEmployee');
            $newStatus = ComplaintStatus::from($request->input('status'));

            $complaint = $this->complaintService->resolveEscalated(
                complaintId:     $complaintId,
                resolutionNotes: $request->input('resolution_notes'),
                newStatus:       $newStatus,
                admin:           $admin,
            );

            Cache::forget('staff.escalated-counts');

            return response()->json([
                'status'  => 'success',
                'message' => "Escalated complaint marked as {$newStatus->label()} and user has been notified.",
                'data'    => $this->complaintService->format($complaint),
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Complaint not found.'], 404);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('StaffAdmin: resolveEscalated failed', [
                'complaint_id' => $complaintId,
                'error'        => $e->getMessage(),
            ]);
            return $this->serverError();
        }
    }

    // =========================================================================
    // SHARED
    // =========================================================================

    private function escalatedStatusCounts(): array
    {
        // 3 COUNT queries on every admin complaint page open.
        // Busted by resolveEscalated(); self-heals within 1 min for cross-controller escalations.
        return Cache::remember('staff.escalated-counts', now()->addMinutes(1), function () {
            // Scoped to whereNotNull('escalated_at') to match listEscalated() —
            // otherwise 'resolved'/'closed' would count the platform-wide
            // totals, not just complaints that passed through escalation (BUG-10).
            $base = fn () => \App\Models\Complaint::whereNotNull('escalated_at');

            return [
                'escalated' => $base()->where('status', ComplaintStatus::ESCALATED->value)->count(),
                'resolved'  => $base()->where('status', ComplaintStatus::RESOLVED->value)->count(),
                'closed'    => $base()->where('status', ComplaintStatus::CLOSED->value)->count(),
            ];
        });
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'An unexpected error occurred. Please try again.',
        ], 500);
    }
}
