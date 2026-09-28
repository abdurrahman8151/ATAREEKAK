<?php

namespace App\Docs;

/**
 * @OA\Get(
 *     path="/api/employees",
 *     operationId="employeesList",
 *     tags={"Employees"},
 *     summary="[system_admin] List all employees",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Response(response=200, description="Employees list")
 * )
 *
 * @OA\Post(
 *     path="/api/employees",
 *     operationId="employeesStore",
 *     tags={"Employees"},
 *     summary="[system_admin] Create an employee account",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(
 *             required={"name","email","password","role"},
 *
 *             @OA\Property(property="name",     type="string"),
 *             @OA\Property(property="email",    type="string", format="email"),
 *             @OA\Property(property="password", type="string", format="password"),
 *             @OA\Property(property="role",     type="string", enum={"admin","staff"})
 *         )
 *     ),
 *
 *     @OA\Response(response=201, description="Employee created")
 * )
 *
 * @OA\Get(
 *     path="/api/employees/{id}",
 *     operationId="employeesShow",
 *     tags={"Employees"},
 *     summary="[system_admin] Get an employee",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Employee object")
 * )
 *
 * @OA\Put(
 *     path="/api/employees/{id}",
 *     operationId="employeesUpdate",
 *     tags={"Employees"},
 *     summary="[system_admin] Update an employee",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(
 *
 *             @OA\Property(property="name",  type="string"),
 *             @OA\Property(property="email", type="string", format="email"),
 *             @OA\Property(property="role",  type="string", enum={"admin","staff"})
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Employee updated")
 * )
 *
 * @OA\Patch(
 *     path="/api/employees/{id}/toggle-active",
 *     operationId="employeesToggleActive",
 *     tags={"Employees"},
 *     summary="[system_admin] Toggle employee active status",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\Response(response=200, description="Status toggled")
 * )
 *
 * @OA\Patch(
 *     path="/api/employees/{id}/reset-password",
 *     operationId="employeesResetPassword",
 *     tags={"Employees"},
 *     summary="[system_admin] Reset employee password",
 *     security={{"bearerAuth":{}}},
 *
 *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *
 *     @OA\RequestBody(required=true,
 *
 *         @OA\JsonContent(required={"password","password_confirmation"},
 *
 *             @OA\Property(property="password",              type="string", format="password"),
 *             @OA\Property(property="password_confirmation", type="string", format="password")
 *         )
 *     ),
 *
 *     @OA\Response(response=200, description="Password reset")
 * )
 */
class EmployeeDocs {}
