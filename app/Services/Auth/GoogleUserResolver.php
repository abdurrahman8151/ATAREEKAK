<?php

namespace App\Services\Auth;

use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * L14 (modular-monolith slice 13): finds or creates the local user for a Google sign-in.
 *
 * Extracted from `GoogleController::callback()`. The rules are unchanged and run in the same order:
 *   1. find by Google id;
 *   2. otherwise find by email and link the Google id (and fill a missing avatar);
 *   3. otherwise create the user from the Google profile.
 * An existing user found by Google id has its avatar refreshed when Google sends a new one.
 *
 * The ban check, the expired-ban clearing, the status flip and the token issuance are NOT here.
 * They stay in the controller, in the same order, so the auth gate remains visible in one place.
 */
final class GoogleUserResolver
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
    ) {}

    /**
     * No type hint on $googleUser: the controller passes whatever Socialite returns (the tests mock it),
     * exactly as before the extraction.
     */
    public function resolve($googleUser): ?User
    {
        $user = $this->userRepo->findByGoogleId($googleUser->getId());

        if (! $user) {
            $user = $this->userRepo->findByEmail($googleUser->getEmail());

            if ($user) {
                $this->userRepo->updateGoogleId($user->id, $googleUser->getId());
                if (empty($user->avatar) && $googleUser->getAvatar()) {
                    $userModel = User::find($user->id);
                    if ($userModel) {
                        $userModel->avatar = $googleUser->getAvatar();
                        $userModel->save();
                        $user = $userModel;
                    }
                }
            } else {
                $firstName = $googleUser->user['given_name'] ?? null;
                $lastName = $googleUser->user['family_name'] ?? null;

                if (is_null($firstName) && ! is_null($googleUser->getName())) {
                    $nameParts = explode(' ', $googleUser->getName(), 2);
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[1] ?? '';
                }

                $userData = [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $googleUser->getEmail(),
                    'password' => bcrypt(Str::random(24)),
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'email_verified_at' => now(),
                    'status' => 1,
                ];
                $user = $this->userRepo->createUser($userData);
            }
        } else {
            $updateData = [];
            if ($user->avatar !== $googleUser->getAvatar() && $googleUser->getAvatar()) {
                $updateData['avatar'] = $googleUser->getAvatar();
            }
            if (! empty($updateData)) {
                $userModel = User::find($user->id);
                if ($userModel) {
                    $userModel->update($updateData);
                    $user = $userModel;
                }
            }
        }

        return $user;
    }
}
