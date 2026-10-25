<?php

namespace App\Docs;

/**
 * ── Admin Auth ────────────────────────────────────────────────────────────────
 *
 * @OA\Post(
 *     path="/api/admin/login",
 *     operationId="adminLogin",
 *     tags={"Admin – Auth"},
 *     summary="Admin login",
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
 *     @OA\Response(response=200, description="Admin JWT returned"),
 *     @OA\Response(response=401, description="Invalid credentials")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/refresh",
 *     operationId="adminRefresh",
 *     tags={"Admin – Auth"},
 *     summary="Refresh admin JWT",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="New token")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/logout",
 *     operationId="adminLogout",
 *     tags={"Admin – Auth"},
 *     summary="Admin logout",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Logged out")
 * )
 *
 * @OA\Post(
 *     path="/api/admin/photo",
 *     operationId="adminUploadPhoto",
 *     tags={"Admin – Auth"},
 *     summary="Upload admin profile photo",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\MediaType(mediaType="multipart/form-data",
 *
 *             @OA\Schema(
 *
 *                 @OA\Property(property="photo", type="string", format="binary")
 *             )
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Photo uploaded")
 * )
 *
 * ── Admin Dashboard ───────────────────────────────────────────────────────────
 *
 * @OA\Get(
 *     path="/api/admin/dashboard",
 *     operationId="adminDashboard",
 *     tags={"Admin – Dashboard"},
 *     summary="Main admin dashboard",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Dashboard data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/stats",
 *     operationId="adminDashboardStats",
 *     tags={"Admin – Dashboard"},
 *     summary="Dashboard statistics",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Stats")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/growth",
 *     operationId="adminDashboardGrowth",
 *     tags={"Admin – Dashboard"},
 *     summary="Dashboard growth metrics",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Growth data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/cities",
 *     operationId="adminDashboardCities",
 *     tags={"Admin – Dashboard"},
 *     summary="City breakdown",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Cities data")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/dashboard/recent",
 *     operationId="adminDashboardRecent",
 *     tags={"Admin – Dashboard"},
 *     summary="Recent activity",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Recent activity")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/export/pdf",
 *     operationId="adminExportPdf",
 *     tags={"Admin – Dashboard"},
 *     summary="[system_admin] Export report as PDF",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="PDF file")
 * )
 *
 * @OA\Get(
 *     path="/api/admin/reports",
 *     operationId="adminReports",
 *     tags={"Admin – Dashboard"},
 *     summary="[system_admin] Show system reports",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Reports data")
 * )
 */
class AdminDocs {}
