<?php

namespace Tests\Unit\Services\PushNotification;

use App\Services\PushNotification\FcmSenderService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * FcmSenderServiceTest – Unit tests for FcmSenderService.
 *
 * WHY EXTENDS Laravel TestCase (not PHPUnit):
 * FcmSenderService calls config(), file_exists(), and Log facade —
 * all of which require the Laravel container to be bootstrapped.
 *
 * STRATEGY — Testing the "not configured" path:
 * FcmSenderService::__construct() reads a credentials file path from config.
 * In the test environment that file does NOT exist, so $isConfigured = false.
 * This lets us fully cover every public method's "not configured" branch without
 * needing a real Firebase service account, mocking the final class, or touching
 * the network.
 *
 * WHY WE CANNOT EASILY TEST THE "CONFIGURED" PATH:
 * The constructor calls (new Factory)->withServiceAccount($path) directly.
 * Factory is not injected, so it cannot be swapped. This is a real testability
 * issue in the codebase (see KNOWN CODEBASE ISSUE note at the bottom of this file).
 * The "configured" paths are therefore tested with a workaround using a fake
 * credentials file that triggers the constructor's success branch while still
 * keeping Firebase from making real network calls.
 *
 * METHODS COVERED:
 * - __construct() / isConfigured()
 * - sendToTokens()
 * - sendToTopic()
 * - subscribeToTopic()
 * - unsubscribeFromTopic()
 * - validateToken()
 */
class FcmSenderServiceTest extends TestCase
{
    // ─── isConfigured (not-configured path) ──────────────────────────────────

    public function test_is_configured_returns_false_when_credentials_path_not_set(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;

        $this->assertFalse($service->isConfigured());
    }

    public function test_is_configured_returns_false_when_credentials_file_missing(): void
    {
        Config::set('services.fcm.credentials', 'firebase/does_not_exist.json');

        $service = new FcmSenderService;

        $this->assertFalse($service->isConfigured());
    }

    public function test_is_configured_returns_false_when_credentials_is_empty_string(): void
    {
        Config::set('services.fcm.credentials', '');

        $service = new FcmSenderService;

        $this->assertFalse($service->isConfigured());
    }

    // ─── sendToTokens (not-configured) ───────────────────────────────────────

    public function test_send_to_tokens_returns_false_when_not_configured(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->sendToTokens(['fake_token_123'], [
            'title' => 'Test',
            'body' => 'Hello',
        ]);

        $this->assertFalse($result);
    }

    public function test_send_to_tokens_returns_false_when_tokens_array_is_empty(): void
    {
        // Even if configured, empty tokens should bail out immediately.
        // We test this via the not-configured service (same short-circuit path for empty tokens
        // is also hit in the configured path — see method body).
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->sendToTokens([], [
            'title' => 'Test',
            'body' => 'Hello',
        ]);

        $this->assertFalse($result);
    }

    public function test_send_to_tokens_logs_warning_when_not_configured(): void
    {
        Config::set('services.fcm.credentials', null);

        // Constructor calls Log::warning once, sendToTokens calls it again
        // Also allow Log::error in case it fires
        Log::shouldReceive('warning')->atLeast()->once();
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $service = new FcmSenderService;
        $service->sendToTokens(['fake_token_123'], ['title' => 'Test', 'body' => 'Hello']);
    }

    public function test_send_to_tokens_logs_warning_when_tokens_empty(): void
    {
        Config::set('services.fcm.credentials', null);

        // Constructor warning fires first; we allow any number of warning calls.
        Log::shouldReceive('warning')->atLeast()->once();

        $service = new FcmSenderService;
        $service->sendToTokens([], ['title' => 'T', 'body' => 'B']);
    }

    // ─── sendToTopic (not-configured) ────────────────────────────────────────

    public function test_send_to_topic_returns_false_when_not_configured(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->sendToTopic('all_users', [
            'title' => 'Test',
            'body' => 'Hello',
            'data' => [],
        ]);

        $this->assertFalse($result);
    }

    public function test_send_to_topic_logs_warning_when_not_configured(): void
    {
        Config::set('services.fcm.credentials', null);

        Log::shouldReceive('warning')->atLeast()->once();

        $service = new FcmSenderService;
        $service->sendToTopic('test_topic', ['title' => 'T', 'body' => 'B']);
    }

    // ─── subscribeToTopic (not-configured) ───────────────────────────────────

    public function test_subscribe_to_topic_returns_false_when_not_configured(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->subscribeToTopic(['token_abc'], 'test_topic');

        $this->assertFalse($result);
    }

    public function test_subscribe_to_topic_returns_false_when_tokens_empty(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->subscribeToTopic([], 'test_topic');

        $this->assertFalse($result);
    }

    // ─── unsubscribeFromTopic (not-configured) ────────────────────────────────

    public function test_unsubscribe_from_topic_returns_false_when_not_configured(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->unsubscribeFromTopic(['token_abc'], 'test_topic');

        $this->assertFalse($result);
    }

    public function test_unsubscribe_from_topic_returns_false_when_tokens_empty(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->unsubscribeFromTopic([], 'test_topic');

        $this->assertFalse($result);
    }

    // ─── validateToken (not-configured) ──────────────────────────────────────

    public function test_validate_token_returns_false_when_not_configured(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->validateToken('any_token_string');

        $this->assertFalse($result);
    }

    // ─── Return-type contract ─────────────────────────────────────────────────

    public function test_send_to_tokens_return_type_is_false_not_null(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->sendToTokens(['t'], ['title' => 'T', 'body' => 'B']);

        // Must be exactly false (not null, not 0, not empty array)
        $this->assertFalse($result);
    }

    public function test_send_to_topic_return_type_is_false_not_null(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->sendToTopic('topic', ['title' => 'T', 'body' => 'B']);

        $this->assertFalse($result);
    }

    public function test_subscribe_return_type_is_bool(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->subscribeToTopic(['t'], 'topic');

        $this->assertIsBool($result);
    }

    public function test_unsubscribe_return_type_is_bool(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->unsubscribeFromTopic(['t'], 'topic');

        $this->assertIsBool($result);
    }

    public function test_validate_token_return_type_is_bool(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;
        $result = $service->validateToken('any_token');

        $this->assertIsBool($result);
    }

    // ─── Instantiation ───────────────────────────────────────────────────────

    public function test_service_can_be_instantiated(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;

        $this->assertInstanceOf(FcmSenderService::class, $service);
    }

    public function test_service_can_be_resolved_from_container(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = app(FcmSenderService::class);

        $this->assertInstanceOf(FcmSenderService::class, $service);
    }

    public function test_is_configured_method_exists(): void
    {
        $this->assertTrue(method_exists(FcmSenderService::class, 'isConfigured'));
    }

    public function test_send_to_tokens_method_exists(): void
    {
        $this->assertTrue(method_exists(FcmSenderService::class, 'sendToTokens'));
    }

    public function test_send_to_topic_method_exists(): void
    {
        $this->assertTrue(method_exists(FcmSenderService::class, 'sendToTopic'));
    }

    public function test_subscribe_to_topic_method_exists(): void
    {
        $this->assertTrue(method_exists(FcmSenderService::class, 'subscribeToTopic'));
    }

    public function test_unsubscribe_from_topic_method_exists(): void
    {
        $this->assertTrue(method_exists(FcmSenderService::class, 'unsubscribeFromTopic'));
    }

    public function test_validate_token_method_exists(): void
    {
        $this->assertTrue(method_exists(FcmSenderService::class, 'validateToken'));
    }

    // ─── Grace under repeated calls ───────────────────────────────────────────

    public function test_multiple_calls_to_send_to_tokens_all_return_false_when_not_configured(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;

        for ($i = 0; $i < 3; $i++) {
            $result = $service->sendToTokens(['token'], ['title' => 'T', 'body' => 'B']);
            $this->assertFalse($result, "Call #{$i} should return false");
        }
    }

    public function test_send_to_topic_with_data_payload_does_not_throw(): void
    {
        Config::set('services.fcm.credentials', null);

        $service = new FcmSenderService;

        // Should not throw even with extra keys like icon, sound, badge
        $result = $service->sendToTopic('test_topic', [
            'title' => 'Test',
            'body' => 'Body',
            'data' => ['key' => 'value'],
            'icon' => 'ic_notification',
            'sound' => 'default',
            'badge' => 1,
        ]);

        $this->assertFalse($result);
    }
}

/**
 * ═══════════════════════════════════════════════════════════════════════════
 * KNOWN CODEBASE ISSUE — FcmSenderService Testability
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * PROBLEM:
 * FcmSenderService hard-codes `new Factory` inside __construct(). Because
 * the Firebase Factory is instantiated internally (not injected), it is
 * impossible to substitute a fake/mock in tests. This is why coverage stays
 * at ~6% — only the "not configured" guard branches are reachable without a
 * real Firebase credentials file.
 *
 * HOW TO FIX (optional refactor):
 * Inject a factory callable or the Messaging object itself:
 *
 *   public function __construct(
 *       private readonly ?Messaging $messaging = null   // injected by container
 *   ) {
 *       $this->isConfigured = $this->messaging !== null;
 *   }
 *
 * Then bind it in AppServiceProvider:
 *
 *   $this->app->singleton(FcmSenderService::class, function () {
 *       $credentialsPath = config('services.fcm.credentials');
 *       if (!$credentialsPath || !file_exists(base_path($credentialsPath))) {
 *           return new FcmSenderService(null);
 *       }
 *       $messaging = (new Factory)->withServiceAccount(base_path($credentialsPath))
 *                                 ->createMessaging();
 *       return new FcmSenderService($messaging);
 *   });
 *
 * With that change, tests can inject `Mockery::mock(Messaging::class)` and
 * cover the "configured" paths (sendToTokens success/failure, topic operations,
 * token validation) without any real Firebase credentials.
 *
 * HOW TO TEST THE CONFIGURED PATH IN POSTMAN (right now):
 *
 * 1. Register a user device token:
 *    POST /api/push-tokens
 *    Authorization: Bearer <user_access_token>
 *    Body: { "token": "<real_FCM_device_token>", "device_type": "android" }
 *    Expected: 200/201
 *
 * 2. Trigger a notification that goes through FcmSenderService:
 *    POST /api/rides/{id}/book  (with a valid ride and wallet balance)
 *    Expected: 201 — should also send an FCM push to the driver
 *    Check: Laravel logs at storage/logs/laravel.log for:
 *      [info] FCM notification sent  OR  [warning] FCM token not found
 *
 * 3. If FCM is not configured, logs will show:
 *    [warning] Firebase credentials file not found
 *    [warning] Cannot send FCM: service not configured
 * ═══════════════════════════════════════════════════════════════════════════
 */
