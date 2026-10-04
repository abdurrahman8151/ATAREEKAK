<?php

namespace App\Repositories;

use App\Enums\AccountStatus;
use App\Interfaces\UserRepositoryInterface;
use App\Models\User;

class UserRepository implements UserRepositoryInterface
{
    protected $model;

    public function __construct(User $user)
    {
        $this->model = $user;
    }

    public function findByGoogleId($googleId)
    {
        return User::where('google_id', $googleId)->first();
    }

    public function updateGoogleId($userId, $googleId)
    {
        return User::where('id', $userId)->update(['google_id' => $googleId]);
    }

    public function createUser(array $data)
    {
        return User::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'gender' => $data['gender'] ?? null,
            'address' => $data['address'] ?? null,
            'google_id' => $data['google_id'] ?? null, // Handle missing key
            'avatar' => $data['avatar'] ?? null,       // Handle missing key

            // RV-12 / un13: honour the caller's status instead of overriding it.
            //
            // This used to hardcode `'status' => 1`, which SILENTLY DISCARDED a deliberate
            // defence: `SignupController` passes `'status' => 0` with the comment "user must
            // verify email before they can log in... starting at 0 adds a second layer of defence",
            // and that value was thrown away, so every self-registered account started ACTIVE
            // regardless. A dead defence is worse than none, because the code reads as though the
            // gate is closed.
            //
            // `AccountStatus::LOGGED_OUT` (0) is the sign-up default when the caller says nothing:
            // an account should not be usable before it has verified its email.
            'status' => $data['status'] ?? AccountStatus::LOGGED_OUT->value,
        ]);
    }

    // app/Repositories/UserRepository.php
    public function updateUserStatus($userId, $status)
    {
        $user = $this->model->findOrFail($userId);
        $user->status = $status;
        $user->save();

        return $user;
    }

    // app/Repositories/UserRepository.php
    public function findByEmail($email)
    {
        // RV-28 (primary-only read): this is the LOGIN / auth lookup. In production with
        // read/write splitting a plain read goes to the replica, so a just-registered user,
        // a just-applied ban, or a password change may not be visible yet — the login would
        // then fail (or, worse, a banned user would authenticate) against stale data. The
        // connection is 'sticky' only WITHIN a request; a fresh request can legitimately hit
        // a lagging replica. Force the primary: correctness beats the read-replica saving on
        // the auth path, and it is decision-free (it can only make auth stricter, never looser).
        return $this->model->where('email', $email)->useWritePdo()->first();
    }
}
