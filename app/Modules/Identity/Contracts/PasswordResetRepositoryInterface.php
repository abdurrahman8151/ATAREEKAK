<?php

namespace App\Modules\Identity\Contracts;

interface PasswordResetRepositoryInterface
{
    public function sendResetLink(array $credentials);

    public function reset(array $credentials);
}
