<?php

namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/wallet/balance",
 *     operationId="walletBalance",
 *     tags={"Wallet"},
 *     summary="Get wallet balance",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Balance object"),
 *     @OA\Response(response=401, description="Unauthenticated")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/initiate",
 *     operationId="walletInitiate",
 *     tags={"Wallet"},
 *     summary="Initiate wallet creation (sends OTP)",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"phone"},
 *
 *             @OA\Property(property="phone", type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="OTP sent for wallet creation")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/verify-and-create",
 *     operationId="walletVerifyCreate",
 *     tags={"Wallet"},
 *     summary="Verify OTP and create wallet",
 *     security={{"bearerAuth":{}}},
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
 *     @OA\Response(response=201, description="Wallet created")
 * )
 *
 * @OA\Get(
 *     path="/api/wallet/requests",
 *     operationId="walletRequests",
 *     tags={"Wallet"},
 *     summary="List own wallet requests",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Requests list")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/request-charge",
 *     operationId="walletRequestCharge",
 *     tags={"Wallet"},
 *     summary="Request a wallet top-up",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"amount"},
 *
 *             @OA\Property(property="amount", type="number"),
 *             @OA\Property(property="notes",  type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Charge request created")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/request-withdraw",
 *     operationId="walletRequestWithdraw",
 *     tags={"Wallet"},
 *     summary="Request a wallet withdrawal",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"amount"},
 *
 *             @OA\Property(property="amount", type="number"),
 *             @OA\Property(property="notes",  type="string")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Withdrawal request created")
 * )
 *
 * @OA\Post(
 *     path="/api/wallet/requests",
 *     operationId="walletRequestStore",
 *     tags={"Wallet"},
 *     summary="Generic wallet request store",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"type","amount"},
 *
 *             @OA\Property(property="type",   type="string", enum={"charge","withdraw"}),
 *             @OA\Property(property="amount", type="number")
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Request created")
 * )
 *
 * @OA\Get(
 *     path="/api/wallet/requests/{id}",
 *     operationId="walletRequestShow",
 *     tags={"Wallet"},
 *     summary="Get a specific wallet request",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Request object"),
 *     @OA\Response(response=404, description="Not found")
 * )
 *
 * @OA\Delete(
 *     path="/api/wallet/requests/{id}",
 *     operationId="walletRequestDelete",
 *     tags={"Wallet"},
 *     summary="Delete a wallet request",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Deleted")
 * )
 */
class WalletDocs {}
