<?php

namespace App\Repositories;

use App\Interfaces\ProfileRepositoryInterface;
use App\Interfaces\VerificationRepositoryInterface;
use App\Models\User;

class VerificationRepository implements VerificationRepositoryInterface
{
    private ProfileRepositoryInterface $profileRepo;

    public function __construct(ProfileRepositoryInterface $profileRepo)
    {
        $this->profileRepo = $profileRepo;
    }

    /**
     * Admin approves a passenger verification request.
     *
     * @param  mixed  $userId
     * @return User
     *
     * @throws \Exception if not in pending state
     */
    public function verifyPassenger($userId)
    {
        $user = User::findOrFail($userId);

        // Only allow approving if status is exactly 'pending'
        if ($user->verification_status !== 'pending') {
            throw new \Exception('Cannot approve passenger: not in pending state');
        }

        $user->update([
            'is_verified_passenger' => true,
            'verification_status' => 'approved',
        ]);

        return $user;
    }

    /**
     * Admin approves a driver verification request.
     *
     * @param  mixed  $userId
     * @return User
     *
     * @throws \Exception if not in pending state
     */
    public function verifyDriver($userId)
    {
        $user = User::findOrFail($userId);

        if ($user->verification_status !== 'pending') {
            throw new \Exception('Cannot approve driver: not in pending state');
        }

        $user->update([
            'is_verified_passenger' => true,
            'is_verified_driver' => true,
            'verification_status' => 'approved',
        ]);

        // RV-19 item 4 / owner decision D4 = B (2026-10-04): this block is GONE.
        //
        // It read `config('system_admin.email')`, and `config/system_admin.php` does not exist, so
        // that call returned NULL; `User::where('email', null)` matches no row under SQL, so
        // `$adminUser` was always null and this "seed 3.0 rating for new drivers" NEVER RAN. It was
        // dormant code that read a config file that was never there.
        //
        // It was not merely useless - it was dangerous. Swapping the key to the canonical
        // `admin.system_admin.phone` would have silently ACTIVATED it, and every driver approval
        // would have started inserting a rating attributed to a wallet phone's user. That is why it
        // sat untouched for so long, and why `WaveZeroVerificationTest` recorded it rather than
        // "fixing" it.
        //
        // The rating now comes from where the owner asked for it: `UserObserver::created()` seeds
        // `rating = 3.0` with `rater_id = null` (platform-assigned) AT SIGNUP, so it applies to
        // every user rather than only to approved drivers, and needs no admin account to exist.
        // ───────────────────────────────────────────────────────────────

        return $user;
    }
}
