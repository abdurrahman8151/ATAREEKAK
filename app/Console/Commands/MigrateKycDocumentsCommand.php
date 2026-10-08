<?php

namespace App\Console\Commands;

use App\Models\Photo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * RV-01 (storage half): move already-uploaded KYC documents off the public disk.
 *
 * **Why this exists.** Decision 1b made KYC consumption authenticated
 * (`StaffDocumentController`, behind the `staff` gate), but the FILES still sit on the public disk, so
 * an old, already-shared `asset('storage/...')` URL keeps resolving. That is recorded as the open
 * action gate in `R2 sec 46`, and it is a data move, not a code change.
 *
 * **Why it is needed BEFORE the documented switch, not after.** `config/filesystems.php:114` says
 * setting `DOCUMENTS_DISK=minio` is "the one-line deploy change that moves identity documents off the
 * public disk", and `StaffDocumentController:85` reads through the same key. So flipping that env var
 * without moving the bytes first makes `$disk->exists($photo->path)` false for EVERY existing row -
 * every staff KYC view 404s. The switch is currently unsafe; this command is what makes it safe.
 *
 * **Safety properties, in order of importance.**
 *
 *  1. COPY, VERIFY, THEN DELETE. The source file is removed only after the copy exists on the target
 *     AND the byte size matches. A half-finished move can therefore never destroy a document: the
 *     worst case is a duplicate, which is recoverable, rather than a lost identity document.
 *  2. IDEMPOTENT. A path already present on the target is skipped, so re-running after a partial run
 *     (or after an interruption) converges instead of re-copying or failing.
 *  3. DRY RUN FIRST. `--dry-run` reports the exact plan and touches nothing.
 *  4. ROWS WITH A MISSING FILE ARE REPORTED, NEVER DELETED. A `photos` row whose file is on neither
 *     disk is left completely alone and counted, so an operator can see it rather than lose it.
 *
 * It moves the `photos` table only - KYC identity documents, which are the rows with a privacy problem
 * and the rows `StaffDocumentController` serves. Profile photos, chat images and complaint attachments
 * live on `uploads_disk` and are AF-5's concern, not this row's.
 */
class MigrateKycDocumentsCommand extends Command
{
    protected $signature = 'kyc:migrate-disk
                            {--from= : Source disk (default: the current documents_disk)}
                            {--to= : Target disk (default: minio)}
                            {--dry-run : Report the plan without moving anything}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Move existing KYC documents from one disk to another (RV-01 storage half)';

    public function handle(): int
    {
        $from = (string) ($this->option('from') ?: 'public');

        // RV-01: `documents_disk` now defaults to the PRIVATE `local` disk, so the destination
        // default follows it rather than hard-coding `minio`. The SOURCE default is `public`
        // because that is the legacy disk holding the already-exposed documents - defaulting
        // `--from` to `documents_disk` would now resolve to `local` and make this a no-op that
        // quietly reports success without moving anything, which is the worst possible default for
        // a data-migration command.
        $to = (string) ($this->option('to') ?: config('filesystems.documents_disk', 'local'));
        $dryRun = (bool) $this->option('dry-run');

        if ($from === $to) {
            $this->error("Source and target disks are the same ('{$from}'). Nothing to do.");

            return self::FAILURE;
        }

        foreach ([$from, $to] as $disk) {
            if (config("filesystems.disks.{$disk}") === null) {
                $this->error("Disk '{$disk}' is not defined in config/filesystems.php.");

                return self::FAILURE;
            }
        }

        $total = Photo::query()->count();

        if ($total === 0) {
            $this->info('No KYC documents found. Nothing to move.');

            return self::SUCCESS;
        }

        $this->line("KYC documents: {$total}");
        $this->line("From disk    : {$from}");
        $this->line("To disk      : {$to}");
        $this->line($dryRun ? 'Mode         : DRY RUN (nothing will be moved)' : 'Mode         : LIVE');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm('Move these documents? Old public URLs will stop resolving.', false)) {
            $this->warn('Aborted. Nothing was moved.');

            return self::SUCCESS;
        }

        $moved = $skipped = $missing = $failed = 0;

        Photo::query()->orderBy('id')->each(function (Photo $photo) use ($from, $to, $dryRun, &$moved, &$skipped, &$missing, &$failed): void {
            $path = $photo->path;

            // Already on the target: this is what makes a re-run after an interruption safe.
            if (Storage::disk($to)->exists($path)) {
                $skipped++;

                return;
            }

            // A row whose bytes are on neither disk is REPORTED, never deleted. The row is left
            // exactly as it was so an operator can investigate it.
            if (! Storage::disk($from)->exists($path)) {
                $missing++;
                $this->warn("  missing (on neither disk, row left alone): id={$photo->id} {$path}");

                return;
            }

            if ($dryRun) {
                $moved++;
                $this->line("  would move: id={$photo->id} {$path}");

                return;
            }

            $sourceSize = Storage::disk($from)->size($path);

            try {
                Storage::disk($to)->put($path, Storage::disk($from)->get($path));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  copy failed: id={$photo->id} {$path} - {$e->getMessage()}");

                return;
            }

            // Verify BEFORE deleting the source. A wrong size means a truncated write; keep the
            // original rather than trading a good file for a bad one.
            if (! Storage::disk($to)->exists($path) || Storage::disk($to)->size($path) !== $sourceSize) {
                $failed++;
                $this->error("  size mismatch after copy, source kept: id={$photo->id} {$path}");

                return;
            }

            try {
                Storage::disk($from)->delete($path);
            } catch (\Throwable $e) {
                // The copy is good; a failed delete only leaves a duplicate, which is the safe
                // direction to fail in. Report it rather than pretending it is done.
                $this->warn("  copied but source delete failed (duplicate left): id={$photo->id} {$path}");
            }

            $moved++;
        });

        $this->newLine();
        $this->line($dryRun ? 'Dry run complete.' : 'Migration complete.');
        $this->table(
            ['moved', 'already on target', 'missing', 'failed'],
            [[
                $dryRun ? "{$moved} (would move)" : $moved,
                $skipped,
                $missing,
                $failed,
            ]]
        );

        if (! $dryRun && $moved > 0) {
            $this->comment("Next step: set DOCUMENTS_DISK={$to} so the read path follows the files.");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
