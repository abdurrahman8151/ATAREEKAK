<?php

namespace Tests\Feature\Review;

use App\DTOs\Auth\SendEmailOtpDTO;
use App\DTOs\Auth\VerifyEmailOtpDTO;
use App\Http\Controllers\API\SignupController;
use App\Interfaces\EmailOtpServiceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RV-16 - the signup mail must leave the database transaction.
 *
 * R2 sec 69. `SignupController::register` PATH B used to send the verification email from INSIDE
 * `DB::beginTransaction()` and `DB::rollBack()` whenever the send reported failure.
 *
 * WHY THAT IS WRONG, in the one case that actually occurs: SMTP can deliver the message and then
 * time out before the response comes back. The user then holds a genuine verification code for an
 * account that was rolled back and no longer exists. They enter the code, it fails, there is no
 * account to verify, and the only way forward is a different email address - while the address they
 * just used has no account at all. The mail succeeded; the database disagreed.
 *
 * After the fix the account is committed first, so a mail failure leaves a real, unverified account
 * - which is precisely the state PATH A already handles by re-sending the code.
 *
 * The fake mailer exists because the bug ONLY exists when the send fails, and a test that cannot
 * fail the send cannot see it. It is one mutable instance flipped with a flag rather than a
 * re-bound service, so the container's instance cache cannot mask a swap.
 *
 * @see SignupController
 */
class RV16SignupMailAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $payload = [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'signup.rv16@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'gender' => 'M',
        'address' => 'دمشق',
    ];

    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new class implements EmailOtpServiceInterface
        {
            public bool $fail = true;

            /** @return array<string, mixed> */
            public function sendOtp(SendEmailOtpDTO $dto): array
            {
                return $this->fail
                    ? ['success' => false, 'message' => 'Failed to send verification email. Please try again.']
                    : ['success' => true, 'message' => 'Verification code sent to your email.'];
            }

            /** @return array<string, mixed> */
            public function verifyOtp(VerifyEmailOtpDTO $dto): array
            {
                return ['success' => true, 'message' => 'ok'];
            }
        };

        $this->app->instance(EmailOtpServiceInterface::class, $this->mailer);
    }

    private function mailWorks(): void
    {
        $this->mailer->fail = false;
    }

    /**
     * THE regression. A failed verification email must NOT destroy the account.
     *
     * Before the fix this asserted `assertDatabaseMissing` and failed: the row had been rolled back.
     *
     * @test
     */
    public function a_failed_verification_email_does_not_delete_the_account(): void
    {
        $response = $this->postJson('/api/auth/signup', $this->payload);

        // The request still did not fully succeed, so 500 is correct and unchanged.
        $response->assertStatus(500);

        $this->assertDatabaseHas('users', ['email' => $this->payload['email']]);

        $user = User::where('email', $this->payload['email'])->firstOrFail();
        $this->assertNull(
            $user->email_verified_at,
            'the account must survive unverified, so the code can be re-sent'
        );
        $this->assertSame(0, (int) $user->status);
    }

    /**
     * The reason the account survives: the user is told the truth. "Registration failed" would push
     * them to choose a different address and orphan the one they just used.
     *
     * @test
     */
    public function the_failure_message_does_not_claim_the_account_was_never_created(): void
    {
        $message = $this->postJson('/api/auth/signup', $this->payload)->json('message');

        $this->assertIsString($message);
        $this->assertStringNotContainsStringIgnoringCase(
            'registration failed',
            $message,
            'the account exists, so this must not read as a failed registration'
        );
        $this->assertStringContainsStringIgnoringCase('created', $message);
    }

    /**
     * The recovery path the whole design rests on. Because the account survived unverified, the same
     * signup request must now take PATH A and re-send rather than erroring on a duplicate.
     *
     * @test
     */
    public function resubmitting_after_a_mail_failure_resends_instead_of_failing(): void
    {
        $this->postJson('/api/auth/signup', $this->payload)->assertStatus(500);

        $this->mailWorks();

        $second = $this->postJson('/api/auth/signup', $this->payload);

        $this->assertContains(
            $second->getStatusCode(),
            [200, 201],
            'the second attempt must resend, not reject'
        );

        $this->assertSame(
            1,
            User::where('email', $this->payload['email'])->count(),
            'resending must not create a second account'
        );
    }

    /**
     * A structural guard, so the ordering cannot silently regress even if the behavioural tests are
     * loosened: within PATH B, `DB::commit()` must appear BEFORE the `sendOtp` call.
     *
     * @test
     */
    public function the_commit_precedes_the_mail_send_in_path_b(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/API/SignupController.php'));

        // PATH A also calls sendOtp, so start measuring from the transaction that matters.
        $pathB = strpos($source, 'DB::beginTransaction()');
        $this->assertNotFalse($pathB, 'PATH B still opens a transaction for the user row');

        $commit = strpos($source, 'DB::commit()', $pathB);
        $send = strpos($source, 'sendOtp(', $pathB);

        $this->assertNotFalse($commit, 'PATH B must still commit the user row');
        $this->assertNotFalse($send, 'PATH B must still send the verification mail');
        $this->assertLessThan(
            $send,
            $commit,
            'the account must be committed BEFORE the mail is sent (RV-16 sec 69)'
        );
    }

    /**
     * Once the mail has been ATTEMPTED there must be no rollback left anywhere after it - that would
     * delete an account the user has already been told exists.
     *
     * Deliberately measured from the `sendOtp(` call, NOT from `DB::commit()`: the commit's own
     * `catch` block legitimately contains a rollback, and that block sits textually after the commit.
     * Checking "no rollback after commit" therefore fails on CORRECT code - which is what the first
     * draft of this test did, and why it was measuring the wrong thing.
     *
     * @test
     */
    public function no_rollback_survives_after_the_mail_has_been_sent(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/API/SignupController.php'));

        $pathB = (int) strpos($source, 'DB::beginTransaction()');
        $send = (int) strpos($source, 'sendOtp(', $pathB);

        $this->assertGreaterThan(0, $pathB, 'PATH B opens a transaction');
        $this->assertGreaterThan(0, $send, 'PATH B sends the mail');

        $this->assertStringNotContainsString(
            'DB::rollBack()',
            substr($source, $send),
            'a rollback after the mail send would delete an account the user was told exists'
        );
    }
}
