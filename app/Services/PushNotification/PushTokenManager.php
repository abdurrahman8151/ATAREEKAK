<?php

namespace App\Services\PushNotification;

use App\Models\User;
use App\Models\PushNotificationToken;
use Illuminate\Support\Facades\Log;

/**
 * Push Token Manager
 *
 * EXTRACTED FROM: PushNotificationService (400+ lines)
 *
 * Single Responsibility: Manage push notification tokens
 *
 * Handles:
 * - Token registration
 * - Token removal
 * - Token retrieval
 * - Token cleanup
 */
final class PushTokenManager
{
    /**
     * Register a push notification token for a user
     */
    public function registerToken(
        int $userId,
        string $token,
        string $deviceType = 'android'
    ): ?PushNotificationToken {
        try {
            // Check if token already exists
            $existingToken = PushNotificationToken::where('token', $token)->first();

            if ($existingToken) {
                // Update existing token with new user
                $existingToken->update([
                    'user_id' => $userId,
                    'device_type' => $deviceType,
                    'is_active' => true,
                    'updated_at' => now()
                ]);
                return $existingToken;
            }

            // Create new token
            return PushNotificationToken::create([
                'user_id' => $userId,
                'token' => $token,
                'device_type' => $deviceType,
                'is_active' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to register push token: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Remove a push notification token
     *
     * When $userId is given the token must belong to that user, so a caller
     * cannot deactivate someone else's token by guessing its value.
     */
    public function removeToken(string $token, ?int $userId = null): bool
    {
        return (bool) PushNotificationToken::where('token', $token)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->update(['is_active' => false]);
    }

    /**
     * Remove all tokens for a user (when they logout)
     */
    public function removeUserTokens(int $userId): bool
    {
        return PushNotificationToken::where('user_id', $userId)
            ->update(['is_active' => false]);
    }

    /**
     * Get active tokens for a user
     */
    public function getUserTokens(int $userId): array
    {
        return PushNotificationToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();
    }

    /**
     * Get active tokens for multiple users
     */
    public function getMultipleUserTokens(array $userIds): array
    {
        return PushNotificationToken::whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();
    }

    /**
     * Mark tokens as invalid
     */
    public function markTokensAsInvalid(array $tokens): int
    {
        if (empty($tokens)) {
            return 0;
        }

        $count = PushNotificationToken::whereIn('token', $tokens)
            ->update(['is_active' => false]);

        Log::info('Removed invalid FCM tokens', ['count' => $count]);

        return $count;
    }

    /**
     * Clean up old inactive tokens
     */
    public function cleanupInactiveTokens(int $days = 30): int
    {
        $count = PushNotificationToken::where('is_active', false)
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();

        Log::info("Cleaned up {$count} inactive push tokens older than {$days} days");

        return $count;
    }

    /**
     * Get notification statistics
     */
    public function getStats(): array
    {
        return [
            'total_tokens' => PushNotificationToken::count(),
            'active_tokens' => PushNotificationToken::where('is_active', true)->count(),
            'android_tokens' => PushNotificationToken::where('device_type', 'android')
                ->where('is_active', true)->count(),
            'ios_tokens' => PushNotificationToken::where('device_type', 'ios')
                ->where('is_active', true)->count(),
            'web_tokens' => PushNotificationToken::where('device_type', 'web')
                ->where('is_active', true)->count(),
        ];
    }
}
