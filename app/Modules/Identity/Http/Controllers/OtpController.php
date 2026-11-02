<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Modules\Identity\Services\WhatsAppOtpService;
use Illuminate\Http\JsonResponse;

class OtpController extends Controller
{
    protected $otpService;

    public function __construct(WhatsAppOtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    /**
     * Send OTP to phone number
     * POST /api/otp/send
     */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $result = $this->otpService->sendOtp(
            $request->phone_number,
            $request->type ?? 'E-PAYMENT'
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Verify OTP
     * POST /api/otp/verify
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->otpService->verifyOtp(
            $request->phone_number,
            $request->otp_code
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
