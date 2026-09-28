<?php

namespace App\Services\PushNotification;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

/**
 * FCM Sender Service (V1 API)
 *
 * Uses Firebase Cloud Messaging V1 API with Service Account
 */
final class FcmSenderService
{
    private $messaging;

    private bool $isConfigured;

    public function __construct()
    {
        try {
            $credentialsPath = config('services.fcm.credentials');

            if (! $credentialsPath || ! file_exists(base_path($credentialsPath))) {
                Log::warning('Firebase credentials file not found', [
                    'path' => $credentialsPath,
                    'full_path' => base_path($credentialsPath),
                ]);
                $this->isConfigured = false;

                return;
            }

            $factory = (new Factory)->withServiceAccount(base_path($credentialsPath));
            $this->messaging = $factory->createMessaging();
            $this->isConfigured = true;

            Log::info('FCM V1 service initialized successfully');
        } catch (\Exception $e) {
            Log::error('Failed to initialize FCM: '.$e->getMessage());
            $this->isConfigured = false;
        }
    }

    /**
     * Check if FCM is properly configured
     */
    public function isConfigured(): bool
    {
        return $this->isConfigured;
    }

    /**
     * Send notification to specific tokens
     *
     * @return array{success: int, failure: int, results: array, invalid_tokens: array}|false
     */
    public function sendToTokens(array $tokens, array $data): array|false
    {
        if (! $this->isConfigured) {
            Log::warning('Cannot send FCM: service not configured');

            return false;
        }

        if (empty($tokens)) {
            Log::warning('Cannot send FCM: no tokens provided');

            return false;
        }

        $aggregatedResults = [
            'success' => 0,
            'failure' => 0,
            'results' => [],
            'invalid_tokens' => [],
        ];

        foreach ($tokens as $token) {
            try {
                $message = $this->buildMessage($token, $data);
                $this->messaging->send($message);

                $aggregatedResults['success']++;
                $aggregatedResults['results'][] = ['token' => $token, 'status' => 'sent'];

                Log::info('FCM notification sent', ['token' => substr($token, 0, 20).'...']);
            } catch (NotFound $e) {
                // Token not found - mark as invalid
                $aggregatedResults['failure']++;
                $aggregatedResults['invalid_tokens'][] = $token;
                Log::warning('FCM token not found', ['token' => substr($token, 0, 20).'...']);
            } catch (InvalidMessage $e) {
                $aggregatedResults['failure']++;
                Log::error('Invalid FCM message', [
                    'token' => substr($token, 0, 20).'...',
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                $aggregatedResults['failure']++;
                Log::error('FCM send failed', [
                    'token' => substr($token, 0, 20).'...',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('FCM batch sent', [
            'success' => $aggregatedResults['success'],
            'failure' => $aggregatedResults['failure'],
        ]);

        return $aggregatedResults;
    }

    /**
     * Send notification to a topic
     */
    public function sendToTopic(string $topic, array $data): bool
    {
        if (! $this->isConfigured) {
            Log::warning('FCM not configured');

            return false;
        }

        try {
            $message = CloudMessage::withTarget('topic', $topic)
                ->withNotification(
                    Notification::create($data['title'], $data['body'])
                )
                ->withData($data['data'] ?? []);

            $this->messaging->send($message);

            Log::info('FCM topic notification sent', ['topic' => $topic]);

            return true;
        } catch (\Exception $e) {
            Log::error('FCM topic send failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Subscribe tokens to a topic
     */
    public function subscribeToTopic(array $tokens, string $topic): bool
    {
        if (! $this->isConfigured || empty($tokens)) {
            return false;
        }

        try {
            $this->messaging->subscribeToTopic($topic, $tokens);
            Log::info('Tokens subscribed to topic', [
                'topic' => $topic,
                'count' => count($tokens),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to subscribe to topic', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Unsubscribe tokens from a topic
     */
    public function unsubscribeFromTopic(array $tokens, string $topic): bool
    {
        if (! $this->isConfigured || empty($tokens)) {
            return false;
        }

        try {
            $this->messaging->unsubscribeFromTopic($topic, $tokens);
            Log::info('Tokens unsubscribed from topic', [
                'topic' => $topic,
                'count' => count($tokens),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to unsubscribe from topic', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build Firebase Cloud Message
     */
    private function buildMessage(string $token, array $data): CloudMessage
    {
        $notification = Notification::create($data['title'], $data['body']);

        $message = CloudMessage::withTarget('token', $token)
            ->withNotification($notification)
            ->withData($data['data'] ?? []);

        // Add Android-specific config if needed
        if (isset($data['icon']) || isset($data['sound']) || isset($data['click_action'])) {
            $androidConfig = [
                'priority' => 'high',
                'notification' => array_filter([
                    'icon' => $data['icon'] ?? null,
                    'sound' => $data['sound'] ?? 'default',
                    'click_action' => $data['click_action'] ?? null,
                ]),
            ];
            $message = $message->withAndroidConfig($androidConfig);
        }

        // Add iOS-specific config if needed
        if (isset($data['badge'])) {
            $apnsConfig = [
                'payload' => [
                    'aps' => [
                        'badge' => $data['badge'],
                        'sound' => $data['sound'] ?? 'default',
                    ],
                ],
            ];
            $message = $message->withApnsConfig($apnsConfig);
        }

        return $message;
    }

    /**
     * Validate a token (optional helper method)
     */
    public function validateToken(string $token): bool
    {
        if (! $this->isConfigured) {
            return false;
        }

        try {
            $message = CloudMessage::withTarget('token', $token)
                ->withData(['test' => 'validation']);

            $this->messaging->validate($message);

            return true;
        } catch (\Exception $e) {
            Log::warning('Token validation failed', [
                'token' => substr($token, 0, 20).'...',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
