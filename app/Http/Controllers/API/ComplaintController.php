<?php

namespace App\Http\Controllers\API;

use Illuminate\Support\Facades\Log;

use App\Http\Controllers\Controller;
use App\Services\Complaint\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\NotificationService;
final class ComplaintController extends Controller
{
    public function __construct(
        private readonly ComplaintService    $complaintService,
        private readonly NotificationService $notificationService,
    ) {}
    // POST /api/complaints
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title'           => 'required|string|max:255',
            'description'     => 'required|string|max:2000',
            'type'            => 'required|in:trip_safety,driver_behavior,passenger_behavior,ride_cancellation,financial_issue,account_issue,technical_issue,other',
            'attachments'     => 'sometimes|array|max:3',
            'attachments.*'   => 'file|mimes:jpeg,jpg,png,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'errors' => $validator->errors()], 422);
        }

        $complaint = $this->complaintService->submit(
            $validator->validated(),
            $request->user(),
            $request->file('attachments', [])
        );

        try {
            $this->notificationService->createNotification(
                $request->user(),
                'complaint_received',
                'تم استلام شكواك',
                'سيتم مراجعة شكواك من قِبل فريق الدعم قريباً.',
                ['complaint_id' => $complaint->id],
                'normal',
                'system'
            );
        } catch (\Throwable $e) { Log::warning('complaint acknowledgement notification failed (non-fatal): ' . $e->getMessage()); }

        return response()->json([
            'status'    => 'success',
            'message'   => 'Complaint submitted successfully.',
            'complaint' => $this->complaintService->format($complaint),
        ], 201);
    }
    // GET /api/complaints
    public function index(Request $request): JsonResponse
    {
        $complaints = $this->complaintService->getUserComplaints($request->user());

        return response()->json([
            'status' => 'success',
            'data'   => $complaints->map(fn($c) => $this->complaintService->format($c))->values(),
        ]);
    }

    // GET /api/complaints/{id}
    public function show(int $id, Request $request): JsonResponse
    {
        try {
            $complaint = $this->complaintService->getForUser($id, $request->user());

            return response()->json([
                'status' => 'success',
                'data'   => $this->complaintService->format($complaint),
            ]);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        }
    }
}
