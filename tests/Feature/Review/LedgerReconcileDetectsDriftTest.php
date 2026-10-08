<?php

namespace Tests\Feature\Review;

use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Payment\WalletTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\Support\Concerns\SeedsSystemWallets;
use Tests\Support\RideBuilder;
use Tests\TestCase;

/**
 * AF-6 criterion 3, `R2 sec 114`: `ledger:reconcile` "reports a real mismatch when one is injected".
 *
 * `R2 sec 112` recorded this clause as NOT PROVEN, and the gap matters more than a missing test: the
 * command is scheduled DAILY at 04:30 against real data (`Kernel:84`), and the one thing it exists to
 * do is notice that a money path moved a balance without recording the legs that explain it. Until a
 * test injects that fault and watches the command catch it, "the ledger is reconciled" is a claim the
 * suite has never actually checked.
 *
 * The existing coverage (`DoubleEntryLedgerTest::the_reconcile_command_runs_against_real_data_and_reports_success`)
 * asserts the command exits 0 on CLEAN data. That is the happy path. This file is the other half.
 *
 * WHY THE FAULT IS INJECTED THE WAY IT IS. `LedgerService::postTransfer` refuses legs that do not sum
 * to zero, so the realistic fault is not "a bad leg" - it is a `wallet_transactions` row with NO legs at
 * all, which is exactly what "a converted money path moved a balance without posting" looks like from
 * the reconciler's side. That is also the only shape that leaves the system-level conservation check
 * green, so these tests pin the PER-WALLET path specifically rather than tripping the coarser guard.
 *
 * THE EXIT CODE IS NOW ASSERTED. Up to `R2 sec 114` these tests deliberately asserted only what the
 * command PRINTS, because whether a daily job should fail the schedule was owner call (a) at `R2 sec
 * 112`. The owner has now answered: **FAIL**. A job whose entire purpose is to be an alarm must not
 * exit 0 while reporting drift, and with every money path ledgered there is no legitimate cause left
 * for unexplained movement. `R2 sec 115` therefore asserts the exit code on every case - 0 when the
 * wallets are explained, 1 when any is not - so the decision cannot be quietly reverted.
 *
 * The expected strings are the command's REAL output. An earlier draft asserted a formatted amount the
 * command never prints and a captured-output helper that returned nothing at all on the clean path; both
 * were measured and replaced rather than worked around.
 */
class LedgerReconcileDetectsDriftTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSystemWallets;

    private const REPORTED = 'wallet(s) with movement the ledger does not yet explain:';

    private const EXPLAINED = 'wallets fully explained by the ledger.';

    private const BALANCED = 'System balances: all ledger legs sum to 0.00 SYP.';

    private User $driver;

    private User $passenger;

    private Wallet $passengerWallet;

    private WalletTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WalletTransactionService::class);

        $this->driver = User::factory()->create([
            'is_verified_driver' => true, 'is_verified_passenger' => true,
            'verification_status' => 'approved', 'password' => bcrypt('password123'),
        ]);
        $this->passenger = User::factory()->create([
            'is_verified_passenger' => true, 'verification_status' => 'approved',
            'password' => bcrypt('password123'),
        ]);

        $this->seedSystemWallets(10_000_000.0);

        $this->passengerWallet = Wallet::create([
            'user_id' => $this->passenger->id,
            'phone_number' => '093'.rand(100000, 999999),
            'wallet_number' => 'WLT-'.substr(bin2hex(random_bytes(5)), 0, 12),
            'balance' => 50_000.0,
        ]);
        $this->passenger->update(['wallet_id' => $this->passengerWallet->id]);
    }

    private function makeBooking(): Booking
    {
        $ride = RideBuilder::forUserId($this->driver->id)
            ->withAttributes([
                'pickup_address' => 'Damascus', 'destination_address' => 'Aleppo',
                'available_seats' => 4, 'price_per_seat' => 10_000.0,
                'payment_method' => 'e-pay', 'booking_type' => 'direct',
                'status' => 'full', 'communication_number' => '0911000000',
            ])
            ->departureTime(now()->addHours(3))
            ->create();

        return Booking::create([
            'user_id' => $this->passenger->id, 'ride_id' => $ride->id,
            'seats' => 2, 'status' => 'confirmed',
            'communication_number' => '0912345678',
            'unit_price' => 10_000.0, 'amount_paid' => 0, 'payment_method' => 'e-pay',
        ]);
    }

    /**
     * Move money without posting legs - the fault, injected at the model layer, which is where a real
     * unconverted path would leave it.
     */
    private function injectUnledgeredMovement(float $amount, string $note = 'RF3 injected fault'): void
    {
        WalletTransaction::create([
            'wallet_id' => $this->passengerWallet->id,
            'user_id' => $this->passenger->id,
            'type' => 'admin_credit',
            'amount' => $amount,
            'previous_balance' => 30_000.0,
            'new_balance' => 30_000.0 + $amount,
            'description' => $note,
            'transaction_id' => 'RF3_'.Str::random(8),
            'status' => 'completed',
        ]);
    }

    private function reconcile(float $threshold = 0.01): PendingCommand
    {
        return $this->artisan('ledger:reconcile', ['--threshold' => $threshold]);
    }

    private function chargedBooking(): Booking
    {
        $booking = $this->makeBooking();
        $this->service->chargePassengerForBooking($booking, $booking->ride, $this->passenger);

        return $booking;
    }

    /**
     * The baseline. Without this, the drift tests could pass because the command reports SOMETHING,
     * rather than because it noticed the fault.
     */
    public function test_clean_data_is_reported_as_fully_explained(): void
    {
        $this->chargedBooking();

        $this->reconcile()
            ->expectsOutputToContain(self::BALANCED)
            ->expectsOutputToContain(self::EXPLAINED)
            ->doesntExpectOutputToContain(self::REPORTED)
            ->assertExitCode(0);
    }

    /**
     * THE FAULT. A money path moves a balance and writes the single-sided row, but posts no legs. That
     * is the exact failure the daily job exists to catch, and it is invisible in `wallet_transactions`
     * alone - which is why the ledger was introduced at all.
     */
    public function test_a_balance_moved_without_legs_is_reported(): void
    {
        $this->chargedBooking();
        $this->injectUnledgeredMovement(777.0);

        $this->reconcile()
            ->expectsOutputToContain(self::REPORTED)
            ->assertExitCode(1);
    }

    /**
     * OWNER DECISION AF-6a (`R2 sec 115`), stated as its own test so it cannot be reverted by accident.
     *
     * Before this, drift produced a full, correct report and exit code 0 - so the daily job logged
     * "here is a wallet the ledger cannot explain" every morning and reported success. The report was
     * never an alert; it was a line in a logfile. The exit code is what turns it into one.
     *
     * This is the needle target: reverting the command to `self::SUCCESS` fails this test and the other
     * exit-code assertions, while the output assertions stay green - proving the tests pin the decision
     * and not merely the printing.
     */
    public function test_per_wallet_drift_fails_the_daily_job(): void
    {
        $this->chargedBooking();
        $this->injectUnledgeredMovement(1.0);

        $this->reconcile()
            ->expectsOutputToContain(self::REPORTED)
            ->assertExitCode(1);
    }

    /**
     * The report is not merely "something was wrong": it names the wallet, which is what makes a 04:30
     * log actionable without a human running SQL.
     *
     * ONE substring per assertion, on purpose. `PendingCommand` registers each `expectsOutputToContain`
     * as a separate Mockery expectation on `BufferedOutput::doWrite`, and Mockery attributes a single
     * `doWrite` call to ONE expectation. Two substrings living on the same line can therefore never both
     * be satisfied - the second is reported missing even though the command printed it. Measured, not
     * assumed: every candidate string matched on its own and failed only when chained with a second
     * substring from the same line. So the wallet and the amount get one test each.
     */
    public function test_the_drift_report_names_the_wallet(): void
    {
        $this->chargedBooking();
        $this->injectUnledgeredMovement(777.0);

        $this->reconcile()
            ->expectsOutputToContain(sprintf('wallet #%d (%s)', $this->passengerWallet->id, $this->passengerWallet->wallet_number))
            ->assertExitCode(1);
    }

    /**
     * And it quantifies the drift, so an operator can tell a rounding wobble from a real leak. The 777.00
     * is this test's own injected fault, and the SIGN carries the meaning: an unledgered CREDIT leaves
     * the ledger more negative than the single-sided table says.
     */
    public function test_the_drift_report_quantifies_the_drift(): void
    {
        $this->chargedBooking();
        $this->injectUnledgeredMovement(777.0);

        $this->reconcile()
            ->expectsOutputToContain('drift -777.00 SYP')
            ->assertExitCode(1);
    }

    /**
     * The per-wallet path is a DIFFERENT check from the system-level one and must not be masked by it.
     * If all legs still sum to zero the fault is per-wallet, and the report has to come from the
     * per-wallet pass - otherwise the coarser guard would hide the specific bug behind a one-line
     * "SYSTEM DOES NOT BALANCE".
     */
    public function test_an_unexplained_wallet_does_not_pretend_the_system_is_unbalanced(): void
    {
        $this->chargedBooking();
        $this->injectUnledgeredMovement(500.0);

        $this->assertSame(0.0, round((float) LedgerEntry::sum('amount'), 2),
            'the injected fault must leave the system balanced, or the coarser guard would mask the per-wallet path');

        $this->reconcile()
            ->expectsOutputToContain(self::BALANCED)
            ->doesntExpectOutputToContain('SYSTEM DOES NOT BALANCE')
            ->expectsOutputToContain(self::REPORTED)
            ->assertExitCode(1);
    }

    /**
     * The tolerance is real and must stay real, pinned at the CENT scale - the scale money actually
     * exists at. Every `amount` column is `decimal(15,2)` (RV-40), so sub-cent drift cannot be built by
     * a real bug, and the command rounds the drift to 2dp before comparing it
     * (`ReconcileLedgerCommand:76`). A reconciler that cried wolf at rounding noise would be switched
     * off within a week.
     */
    public function test_drift_at_the_default_tolerance_is_tolerated_and_a_cent_more_is_not(): void
    {
        $this->chargedBooking();
        $this->injectUnledgeredMovement(0.01, 'RF3 exactly at the tolerance');

        $this->reconcile(0.01)
            ->doesntExpectOutputToContain(self::REPORTED)
            ->assertExitCode(0);

        $this->injectUnledgeredMovement(0.01, 'RF3 one cent beyond the tolerance');

        $this->reconcile(0.01)
            ->expectsOutputToContain(self::REPORTED)
            ->assertExitCode(1);
    }

    /**
     * And the inverse, pinned so `--threshold` is not mistaken for a mute button and nobody "fixes" a
     * noisy schedule by turning it up.
     *
     * With the job now failing on drift (`R2 sec 115`), this pair becomes load-bearing rather than
     * cosmetic: a tolerance set ABOVE the real drift returns exit 0, so raising the threshold is
     * exactly how someone would silence the alarm the previous change installed. It is an explicit
     * operator choice and it is documented here so it cannot be discovered by accident.
     */
    public function test_a_threshold_raised_above_the_drift_hides_it(): void
    {
        $this->chargedBooking();
        $this->injectUnledgeredMovement(25.0);

        $this->reconcile(0.01)
            ->expectsOutputToContain(self::REPORTED)
            ->assertExitCode(1);

        $this->reconcile(100.0)
            ->doesntExpectOutputToContain(self::REPORTED)
            ->assertExitCode(0);
    }

    /**
     * Sub-cent drift is invisible at ANY threshold, because the drift is rounded to 2dp before the
     * comparison. That is correct for this schema, and it is pinned deliberately: it is the reason
     * `--threshold 0.0` cannot be used to "see everything", and the next person to try should not have
     * to rediscover it.
     */
    public function test_drift_smaller_than_a_cent_is_invisible_even_at_a_zero_threshold(): void
    {
        $this->chargedBooking();
        $this->injectUnledgeredMovement(0.004, 'RF3 sub-cent noise');

        $this->reconcile(0.0)
            ->doesntExpectOutputToContain(self::REPORTED)
            ->assertExitCode(0);
    }
}
