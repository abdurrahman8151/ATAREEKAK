<?php

namespace App\Modules\Identity\Repositories;

use App\Modules\Identity\Contracts\PasswordResetRepositoryInterface;
use App\Modules\Identity\Contracts\TokenRevokerInterface;
use Illuminate\Support\Facades\Password;

class PasswordResetRepository implements PasswordResetRepositoryInterface
{
    public function __construct(private readonly TokenRevokerInterface $tokenRevoker) {}

    public function sendResetLink(array $credentials)
    {
        return Password::sendResetLink($credentials);
    }

    public function reset(array $credentials)
    {
        return Password::reset($credentials, function ($user, $password) {
            $user->update([
                'password' => bcrypt($password),
                'status' => 1,
            ]);

            // This is the critical line — revokes all refresh tokens
            // AND increments token_version to kill all access tokens
            $this->tokenRevoker->revokeAllTokens($user->id);
        });
    }
}
