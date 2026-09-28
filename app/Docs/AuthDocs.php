<?php

namespace App\Docs;

/**
 * ── OTP (WhatsApp) ────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/otp/send",
 *     operationId="otpSend",
 *     tags={"OTP"},
 *     summary="Send OTP via WhatsApp",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"phone"},
 *
 *             @OA\Property(property="phone", type="string", example="+9627XXXXXXXX")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="OTP sent")
 * )
 *
 * @OA\Post(
 *     path="/api/otp/verify",
 *     operationId="otpVerify",
 *     tags={"OTP"},
 *     summary="Verify WhatsApp OTP",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"phone","otp"},
 *
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="otp",   type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="OTP verified")
 * )
 *
 * ── OTP (TextMeBot) ───────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/textme-otp/send",
 *     operationId="textmeOtpSend",
 *     tags={"OTP"},
 *     summary="Send OTP via TextMeBot",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"phone"},
 *
 *             @OA\Property(property="phone", type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="OTP sent")
 * )
 *
 * @OA\Post(
 *     path="/api/textme-otp/verify",
 *     operationId="textmeOtpVerify",
 *     tags={"OTP"},
 *     summary="Verify TextMeBot OTP",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"phone","otp"},
 *
 *             @OA\Property(property="phone", type="string"),
 *             @OA\Property(property="otp",   type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="OTP verified")
 * )
 *
 * ── Email Verification ────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/email-verification/send",
 *     operationId="emailVerifSend",
 *     tags={"Email Verification"},
 *     summary="Send email verification code",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"email"},
 *
 *             @OA\Property(property="email", type="string", format="email")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Code sent")
 * )
 *
 * @OA\Post(
 *     path="/api/email-verification/verify",
 *     operationId="emailVerifVerify",
 *     tags={"Email Verification"},
 *     summary="Verify the emailed code",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"email","code"},
 *
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="code",  type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Email verified")
 * )
 *
 * @OA\Post(
 *     path="/api/email-verification/resend",
 *     operationId="emailVerifResend",
 *     tags={"Email Verification"},
 *     summary="Resend email verification code",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"email"},
 *
 *             @OA\Property(property="email", type="string", format="email")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Code resent")
 * )
 *
 * ── Auth ──────────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/auth/signup",
 *     operationId="authSignup",
 *     tags={"Authentication"},
 *     summary="Register a new user",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(
 *             required={"name","email","password","password_confirmation","phone"},
 *
 *             @OA\Property(property="name",                  type="string"),
 *             @OA\Property(property="email",                 type="string", format="email"),
 *             @OA\Property(property="password",              type="string", format="password"),
 *             @OA\Property(property="password_confirmation", type="string", format="password"),
 *             @OA\Property(property="phone",                 type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Registered – returns tokens"),
 *     @OA\Response(response=422, description="Validation error")
 * )
 *
 * @OA\Post(
 *     path="/api/auth/login",
 *     operationId="authLogin",
 *     tags={"Authentication"},
 *     summary="Login and receive JWT tokens",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"email","password"},
 *
 *             @OA\Property(property="email",    type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Returns access_token + refresh_token"),
 *     @OA\Response(response=401, description="Invalid credentials")
 * )
 *
 * @OA\Post(
 *     path="/api/auth/refresh",
 *     operationId="authRefresh",
 *     tags={"Authentication"},
 *     summary="Refresh JWT access token",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="New access token")
 * )
 *
 * ── Password Reset (3-step OTP flow) ─────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/auth/password/forgot",
 *     operationId="passwordForgot",
 *     tags={"Authentication"},
 *     summary="Step 1 – request password-reset OTP",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"email"},
 *
 *             @OA\Property(property="email", type="string", format="email")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="OTP dispatched to email")
 * )
 *
 * @OA\Post(
 *     path="/api/auth/password/verify-otp",
 *     operationId="passwordVerifyOtp",
 *     tags={"Authentication"},
 *     summary="Step 2 – verify OTP and receive reset token",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"email","otp"},
 *
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="otp",   type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Returns reset_token")
 * )
 *
 * @OA\Post(
 *     path="/api/auth/password/reset",
 *     operationId="passwordReset",
 *     tags={"Authentication"},
 *     summary="Step 3 – set new password using reset token",
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"reset_token","password","password_confirmation"},
 *
 *             @OA\Property(property="reset_token",           type="string"),
 *             @OA\Property(property="password",              type="string", format="password"),
 *             @OA\Property(property="password_confirmation", type="string", format="password")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Password updated")
 * )
 *
 * ── Session ───────────────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/user",
 *     operationId="authUser",
 *     tags={"Authentication"},
 *     summary="Get authenticated user",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="User object"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/logout",
 *     operationId="authLogout",
 *     tags={"Authentication"},
 *     summary="Logout (invalidate JWT)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Logged out"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 */
class AuthDocs {}
