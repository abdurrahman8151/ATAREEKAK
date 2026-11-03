<?php

namespace App\Modules\Identity\Contracts;

// Moved from app/Interfaces/UserRepositoryInterface.php in stage 1 phase 1(b). The port now lives in
// the Identity module's Contracts, and app/Interfaces is empty by ratchet (ModuleBoundaryTest).
interface UserRepositoryInterface
{
    public function createUser(array $data);

    public function updateUserStatus($userId, $status);

    public function findByEmail($email);

    public function findByGoogleId($googleId); // Add this

    public function updateGoogleId($userId, $googleId); // Add this
}
