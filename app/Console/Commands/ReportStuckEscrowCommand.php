<?php

namespace App\Console\Commands;

use App\Models\Booking;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RV-10, decision D2 first half: the READ-ONLY stuck-escrow reporter.
 *
 * A ride booked with e-pay puts the passenger's money into the SyCash wallet ("escrow") and is supposed
 * to come back out when the booking settles - as a driver payout, a refund, a no-show settlement, or a
 * cancellation. When a booking never settles, that money stays in SyCash indefinitely. Nobody can see
 * it, so it is invisible: the admin financial dashboards report escrow IN and escrow OUT as two
 * separate totals and never their difference, so an amount that came in and never left looks like a
 * healthy float rather than a leak.
 *
 * This command exists to make that money visible. It moves NOTHING: it only selects and prints.
 *
 * WHY NOT A LIST OF LedgerType CASES. The obvious query is "escrow_received rows with no matching
 * escrow_release", and it is wrong here. LedgerType documents that this vocabulary has already drifted
 * three times, and escrow can leave through several different routes (driver payout, cancellation
 * refund, time-based refund, passenger/driver no-show, cash-ride fee paths). Any hand-maintained list of
 * settlement types is a list that will silently start reporting settled bookings as stuck the first time
 * someone adds a settlement type. It would fail in the direction that makes the report untrustworthy.
 *
 * So correlation is by MONEY, not by NAME: for each booking, the SyCash wallet's own recorded balance
 * movement (new_balance - previous_balance, summed over that booking's legs). If the net is positive,
 * the wallet took in more than it gave back, and the difference is still sitting there. That holds for
 * every settlement route, including routes that do not exist yet.
 *
 * This depends on previous_balance / new_balance being populated. Both are NOT NULL in the schema
 * (verified by test, not assumed), so a leg can never be uncorrelatable - which is why there is no
 * "cannot determine" path here. If a future migration relaxes that to nullable, a NULL leg must be
 * surfaced explicitly rather than summed as if it were zero.
 *
 * WINDOW W IS NOT ASSUMED. D2 says the escalation window should come from this reporter's data, so the
 * command prints an age histogram instead of filtering on a window. --min-age-hours narrows the detail
 * view once the owner has picked a threshold.
 */
class ReportStuckEscrowCommand extends Command
{
    protected $signature = 'escrow:stuck-report
                            {--min-age-hours= : only include escrow older than this many hours}
                            {--bucket=24,72,168,720 : upper bounds in hours for the age histogram}';

    protected $description = 'READ-ONLY: report ride escrow still held in SyCash, with an age histogram (RV-10 / D2)';

    /** The reference prefix WalletTransactionService writes: "booking:{id}". */
    private const REFERENCE_PREFIX = 'booking:';

    public function handle(): int
    {
        $rows = $this->heldEscrows();

        if ($rows->isEmpty()) {
            $this->info('No escrow is currently held in SyCash. Nothing is stuck.');
            $this->line('(This command moves no money; it only reads.)');

            return self::SUCCESS;
        }

        $rows = $this->attachBookingsAndAge($rows);

        // The histogram is always built from the FULL population. D2 says window W is chosen from
        // this reporter's data, so filtering the histogram with a guessed threshold would let the
        // window be chosen from data the window already selected - the filter would decide the
        // answer it exists to inform. Only the summary and the detail view narrow.
        $all = $rows;

        $minAge = $this->option('min-age-hours');
        $minAge = ($minAge === null || $minAge === '') ? null : (float) $minAge;
        if ($minAge !== null) {
            $rows = $rows->filter(fn ($r) => $r['age_hours'] >= $minAge)->values();
        }

        $this->renderSummary($rows);
        $this->renderHistogram($all);
        $this->renderDetail($rows);

        $this->newLine();
        $this->line('<comment>This command moves no money. Window W is not assumed - choose it from the histogram above.</comment>');

        return self::SUCCESS;
    }

    /**
     * One row per booking whose SyCash wallet net movement is positive.
     *
     * Grouping is by (reference, wallet_id) and restricted to wallets that actually received an
     * escrow leg, because every booking writes a row to the PASSENGER's wallet under the same
     * reference. Without the has-escrow filter the passenger's own debit would look like held money.
     *
     * @return Collection<int, object>
     */
    private function heldEscrows()
    {
        return DB::table('wallet_transactions')
            ->select('reference', 'wallet_id')
            ->selectRaw('MIN(created_at) as first_seen')
            ->selectRaw('SUM(new_balance - previous_balance) as net')
            ->selectRaw("SUM(CASE WHEN type = 'escrow_received' THEN 1 ELSE 0 END) as escrow_in_legs")
            ->where('reference', 'like', self::REFERENCE_PREFIX.'%')
            ->groupBy('reference', 'wallet_id')
            ->havingRaw('escrow_in_legs > 0')
            ->havingRaw('net > 0')
            ->orderBy('net', 'desc')
            ->get();
    }

    /**
     * Join each booking to its passenger and ride, and age it from the ride's departure time.
     *
     * Departure time is the honest age: escrow is not "stuck" the moment a booking is made, it is stuck
     * relative to a departure that has passed. If a ride has no departure_time the escrow receipt's own
     * timestamp is used instead, and flagged, rather than the row being dropped from the report.
     *
     * @param  Collection<int, object>  $rows
     * @return Collection<int, array>
     */
    private function attachBookingsAndAge($rows)
    {
        $ids = $rows->map(fn ($r) => (int) str_replace(self::REFERENCE_PREFIX, '', $r->reference))->all();

        $bookings = Booking::query()
            ->with(['user', 'ride'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $now = now();

        return $rows->map(function ($r) use ($bookings, $now) {
            $id = (int) str_replace(self::REFERENCE_PREFIX, '', $r->reference);
            $booking = $bookings->get($id);
            $ride = $booking?->ride;

            $departure = $ride?->departure_time ? Carbon::parse($ride->departure_time) : null;
            $basis = $departure ?? Carbon::parse($r->first_seen);

            return [
                'booking_id' => $id,
                'passenger' => $booking?->user
                    ? trim(($booking->user->first_name ?? '').' '.($booking->user->last_name ?? ''))
                    : 'unknown',
                'ride_id' => $ride?->id,
                'seats' => $booking?->seats,
                'amount' => (float) $r->net,
                'age_hours' => $basis->diffInHours($now),
                'age_basis' => $departure ? 'departure' : 'escrow receipt',
            ];
        })->sortByDesc('age_hours')->values();
    }

    /** @param Collection<int, array> $rows */
    private function renderSummary($rows): void
    {
        $this->newLine();
        $this->info(sprintf(
            'Escrow still held in SyCash: %d booking(s), %s total.',
            $rows->count(),
            number_format($rows->sum('amount'), 2)
        ));
    }

    /**
     * The histogram IS the deliverable: D2 says the escalation window W comes from this data, so it is
     * printed unconditionally and never filtered by --min-age-hours (that option narrows the detail
     * view only).
     *
     * @param  Collection<int, array>  $rows
     */
    private function renderHistogram($rows): void
    {
        $bounds = collect(explode(',', (string) $this->option('bucket')))
            ->map(fn ($b) => (float) trim($b))
            ->filter(fn ($b) => $b > 0)
            ->sort()
            ->values();

        if ($bounds->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('<info>Age histogram (the data window W should be chosen from)</info>');

        $table = [];
        $previous = 0.0;
        foreach ($bounds as $bound) {
            $slice = $rows->filter(fn ($r) => $r['age_hours'] >= $previous && $r['age_hours'] < $bound);
            $table[] = [
                $this->bucketLabel($previous, $bound),
                $slice->count(),
                number_format($slice->sum('amount'), 2),
            ];
            $previous = $bound;
        }
        $over = $rows->filter(fn ($r) => $r['age_hours'] >= $previous);
        $table[] = [
            $this->bucketLabel($previous, null),
            $over->count(),
            number_format($over->sum('amount'), 2),
        ];

        $this->table(['Age', 'Bookings', 'Amount held'], $table);
    }

    private function bucketLabel(float $from, ?float $to): string
    {
        $fmt = function (float $h) {
            return $h < 24 ? sprintf('%gh', $h) : sprintf('%.0fd', $h / 24);
        };

        return $to === null
            ? sprintf('%s and older', $fmt($from))
            : sprintf('%s - %s', $fmt($from), $fmt($to));
    }

    /** @param Collection<int, array> $rows */
    private function renderDetail($rows): void
    {
        if ($rows->isEmpty()) {
            $this->line('Nothing meets the requested --min-age-hours.');

            return;
        }

        $this->newLine();
        $table = $rows->take(100)->map(fn ($r) => [
            $r['booking_id'],
            $r['ride_id'] ?? '-',
            mb_substr($r['passenger'], 0, 28),
            $r['seats'] ?? '-',
            number_format($r['amount'], 2),
            $this->humanAge($r['age_hours']),
            $r['age_basis'],
        ])->all();

        $this->table(['Booking', 'Ride', 'Passenger', 'Seats', 'Held', 'Age', 'Aged from'], $table);

        if ($rows->count() > 100) {
            $this->line(sprintf('... and %d more.', $rows->count() - 100));
        }
    }

    private function humanAge(float $hours): string
    {
        return $hours < 48
            ? sprintf('%dh', $hours)
            : sprintf('%dd %dh', intdiv((int) $hours, 24), (int) $hours % 24);
    }
}
