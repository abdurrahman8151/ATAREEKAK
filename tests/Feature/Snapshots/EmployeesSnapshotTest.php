<?php

namespace Tests\Feature\Snapshots;

/**
 * Batch H - employee management (system_admin only).
 *
 * The staff directory, the create door, read/update of one employee, the password reset and the
 * active toggle. The target of the four single-employee routes is a real support-agent row created
 * in `prepare`, so the ids in the URI are facts rather than guesses.
 */
final class EmployeesSnapshotTest extends ApiSnapshotTestCase
{
    public static function cases(): array
    {
        return [
            'employees/index' => [
                'route' => 'GET /api/employees',
                'method' => 'GET',
                'uri' => '/api/employees',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->staffEmployee(),
                'expect' => 200,
            ],
            'employees/store' => [
                'route' => 'POST /api/employees',
                'method' => 'POST',
                'uri' => '/api/employees',
                'actor' => 'admin',
                'body' => [
                    'username' => 'snapshot_created_agent',
                    'email' => 'snapshot.created.agent@example.test',
                    'password' => 'snapshot-password',
                    'first_name' => 'Snapshot',
                    'last_name' => 'Created',
                    'role' => 'support_agent',
                ],
                'expect' => 201,
            ],
            'employees/show' => [
                'route' => 'GET /api/employees/{id}',
                'method' => 'GET',
                'uri' => '/api/employees/{employee}',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->targetEmployee(),
                'expect' => 200,
            ],
            'employees/update' => [
                'route' => 'PUT /api/employees/{id}',
                'method' => 'PUT',
                'uri' => '/api/employees/{employee}',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->targetEmployee(),
                'body' => [
                    'first_name' => 'Renamed',
                    'last_name' => 'BySnapshot',
                ],
                'expect' => 200,
            ],
            'employees/reset-password' => [
                'route' => 'PATCH /api/employees/{id}/reset-password',
                'method' => 'PATCH',
                'uri' => '/api/employees/{employee}/reset-password',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->targetEmployee(),
                'body' => ['new_password' => 'snapshot-new-password'],
                'expect' => 200,
            ],
            'employees/toggle-active' => [
                'route' => 'PATCH /api/employees/{id}/toggle-active',
                'method' => 'PATCH',
                'uri' => '/api/employees/{employee}/toggle-active',
                'actor' => 'admin',
                'prepare' => static fn (ApiSnapshotTestCase $test) => $test->targetEmployee(),
                'expect' => 200,
            ],
        ];
    }

    /** The support-agent row the single-employee routes act on, exposed as {employee}. */
    public function targetEmployee(): string
    {
        $this->caseVars['{employee}'] = (string) $this->staffEmployee()->id;

        return $this->caseVars['{employee}'];
    }
}
