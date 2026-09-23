<?php

namespace App\Services\PushNotification;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Push Notification Service (REFACTORED)
 *
 * BEFORE: 400+ lines doing everything
 * AFTER: 80 lines - orchestrates token manager and FCM sender
 *
 * Single Responsibility: Coordinate push notification operations
 *
 * Delegates to:
 * - PushTokenManager: Token management
 * - FcmSenderService: Sending notifications
 */
final class PushNotificationService
{
    public function __construct(
        private readonly PushTokenManager $tokenManager,
        private readonly FcmSenderService $fcmSender
    ) {}

    /**
     * Register a push notification token
     */
    public function registerToken(
        int $userId,
        string $token,
        string $deviceType = 'android'
    ): mixed {
        return $this->tokenManager->registerToken($userId, $token, $deviceType);
    }

    /**
     * Remove a token, optionally scoped to its owner
     */
    public function removeToken(string $token, ?int $userId = null): bool
    {
        return $this->tokenManager->removeToken($token, $userId);
    }

    /**
     * Remove all tokens for a user
     */
    public function removeUserTokens(int $userId): bool
    {
        return $this->tokenManager->removeUserTokens($userId);
    }

    /**
     * Send push notification to a specific user
     */
    public function sendToUser(User $user, array $data): bool
    {
        $tokens = $this->tokenManager->getUserTokens($user->id);

        if (empty($tokens)) {
            Log::info("No active tokens found for user: {$user->id}");
            return false;
        }

        return $this->sendToTokens($tokens, $data);
    }

    /**
     * Send push notification to multiple users
     */
    public function sendToUsers(array $userIds, array $data): bool
    {
        $tokens = $this->tokenManager->getMultipleUserTokens($userIds);

        if (empty($tokens)) {
            Log::info("No active tokens found for users: " . implode(',', $userIds));
            return false;
        }

        return $this->sendToTokens($tokens, $data);
    }

    /**
     * Send push notification to specific tokens
     */
    public function sendToTokens(array $tokens, array $data): bool
    {
        if (empty($tokens)) {
            return false;
        }

        $result = $this->fcmSender->sendToTokens($tokens, $data);

        // Handle invalid tokens
        if ($result && !empty($result['invalid_tokens'])) {
            $this->tokenManager->markTokensAsInvalid($result['invalid_tokens']);
        }

        return $result !== false;
    }

    /**
     * Send notification to a topic (broadcast)
     */
    public function sendToTopic(string $topic, array $data): bool
    {
        return $this->fcmSender->sendToTopic($topic, $data);
    }

    /**
     * Subscribe user to a topic
     */
    public function subscribeToTopic(int $userId, string $topic): bool
    {
        $tokens = $this->tokenManager->getUserTokens($userId);

        if (empty($tokens)) {
            return false;
        }

        return $this->fcmSender->subscribeToTopic($tokens, $topic);
    }

    /**
     * Unsubscribe user from a topic
     */
    public function unsubscribeFromTopic(int $userId, string $topic): bool
    {
        $tokens = $this->tokenManager->getUserTokens($userId);

        if (empty($tokens)) {
            return false;
        }

        return $this->fcmSender->unsubscribeFromTopic($tokens, $topic);
    }

    /**
     * Clean up old inactive tokens
     */
    public function cleanupInactiveTokens(int $days = 30): int
    {
        return $this->tokenManager->cleanupInactiveTokens($days);
    }

    /**
     * Get notification statistics
     */
    public function getStats(): array
    {
        return $this->tokenManager->getStats();
    }

    /**
     * Test notification (for debugging)
     */
    public function sendTestNotification(int $userId): bool
    {
        $user = User::find($userId);

        if (!$user) {
            return false;
        }

        return $this->sendToUser($user, [
            'title' => 'Test Notification',
            'body' => 'This is a test notification from your app!',
            'data' => ['test' => true]
        ]);
    }
}
