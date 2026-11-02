<?php

namespace App\Modules\Identity\Contracts;

interface VerificationRepositoryInterface
{
    public function verifyPassenger($userId);

    public function verifyDriver($userId);
}
