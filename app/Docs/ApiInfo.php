<?php

namespace App\Docs;

/**
 * @OA\Info(
 *     title="SyRide API",
 *     version="1.0.0",
 *     description="SyRide Ride-Sharing Platform – Full API Reference"
 * )
 *
 * @OA\Server(
 *     url=L5_SWAGGER_CONST_HOST,
 *     description="Local Development Server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class ApiInfo {}
