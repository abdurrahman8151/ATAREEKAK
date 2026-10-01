<?php

namespace App\Repositories;

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
            'status' => 1,
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
