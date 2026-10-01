<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RV-40 (final bullet) — one no-show report per (booking, reporter).
 *
 * WHY A BACKSTOP AND NOT A NEW RULE
 *
 * The service already refuses duplicates two ways, which is why this migration only
 * hardens an existing invariant instead of changing behaviour:
 *
 *  - `Noshowservice::reportPassengerNoShow` / the passenger counterpart both reject a
 *    second live report with `status IN ('pending','disputed')` (L123, L246).
 *  - A resolved report leaves the duplicate path unreachable anyway: `applyPenalty`
 *    requires the booking still be `confirmed` (L531) and every settlement path then
 *    writes the booking to `no_show` (L547, L590), so the entry precondition at L92
 *    ("booking must be confirmed") rejects any later report for that booking.
 *  - Concurrency is already closed by `Booking::lockForUpdate()` at the top of the
 *    reporting transaction, so two simultaneous identical reports serialise.
 *
 * So duplicates are unreachable through the code that exists today. What the database
 * does NOT enforce is the invariant itself: any future writer that bypasses the service
 * (a seeder, an admin tool, a batch job, a new endpoint) could file a second report for the
 * same booking from the same reporter — and a duplicate report is a money hazard, because
 * report resolution releases escrow to the reporter.
 *
 * SCOPE
 *
 * Adds only the unique index. It removes no capability that any current code path can
 * reach (see above), and `booking_id` is NOT NULL, so there is no NULL-multiple loophole.
 *
 * DATA HAZARD HANDLED EXPLICITLY
 *
 * Adding a UNIQUE index to a table that already holds duplicates would abort the deploy.
 * This checks first and refuses loudly with the offending count and sample, instead of
 * silently deleting or merging rows — a human decides how to resolve real reports.
 */
return new class extends Migration
{
    private const TABLE = 'noshow_reports';

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return; // index creation is a MySQL concern here; other drivers are left alone
        }

        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->indexExists()) {
            return; // idempotent: already applied
        }

        $duplicates = DB::select(
            'SELECT booking_id, reporter_id, COUNT(*) c
             FROM noshow_reports
             GROUP BY booking_id, reporter_id
             HAVING c > 1'
        );

        if ($duplicates !== []) {
            $sample = collect($duplicates)->take(5)
                ->map(fn ($d) => "booking #{$d->booking_id} / reporter #{$d->reporter_id} (x{$d->c})")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add unique(booking_id, reporter_id) to noshow_reports: '
                .count($duplicates).' duplicate group(s) already exist, e.g. '.$sample
                .'. Resolve them manually — this migration will not discard real reports.'
            );
        }

        DB::statement(
            'ALTER TABLE noshow_reports
             ADD UNIQUE KEY uq_noshow_report_booking_reporter (booking_id, reporter_id)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! $this->indexExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE noshow_reports DROP INDEX uq_noshow_report_booking_reporter'
        );
    }

    private function indexExists(): bool
    {
        $row = DB::select(
            'SELECT COUNT(*) c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND INDEX_NAME = ?',
            [self::TABLE, 'uq_noshow_report_booking_reporter']
        )[0] ?? null;

        return $row !== null && (int) $row->c > 0;
    }
};
