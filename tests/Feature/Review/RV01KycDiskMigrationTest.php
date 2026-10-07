<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * RV-01 storage half: the file-move tool that makes the documented DOCUMENTS_DISK switch safe.
 *
 * The failure mode this guards against is severe and irreversible: an identity document deleted from
 * the source before the copy is verified is gone forever. Every test here is about that, plus the two
 * properties that make the command safe to actually run on production data - it is idempotent, and a
 * row whose file is on neither disk is reported rather than touched.
 */
class RV01KycDiskMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = 'kyc_source';

    private const TO = 'kyc_target';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.'.self::FROM => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/kyc-source'), 'throw' => false],
            'filesystems.disks.'.self::TO => ['driver' => 'local', 'root' => storage_path('framework/testing/disks/kyc-target'), 'throw' => false],
        ]);

        Storage::fake(self::FROM);
        Storage::fake(self::TO);
    }

    private function photo(string $path): Photo
    {
        $user = User::factory()->create();

        return Photo::create([
            'user_id' => $user->id,
            'type' => 'face_id',
            'path' => $path,
        ]);
    }

    private function run_(array $options = []): PendingCommand
    {
        return $this->artisan('kyc:migrate-disk', array_merge([
            '--from' => self::FROM,
            '--to' => self::TO,
            '--force' => true,
        ], $options));
    }

    public function test_it_moves_a_document_and_removes_it_from_the_public_source(): void
    {
        Storage::disk(self::FROM)->put('documents/face1.jpg', 'IDENTITY-BYTES');

        $photo = $this->photo('documents/face1.jpg');

        $this->run_()->assertExitCode(0);

        $this->assertSame('IDENTITY-BYTES', Storage::disk(self::TO)->get('documents/face1.jpg'),
            'The bytes must be readable from the target disk afterwards.');
        $this->assertFalse(
            Storage::disk(self::FROM)->exists('documents/face1.jpg'),
            'The source copy must be deleted, or the old public URL keeps resolving and RV-01 stays open.'
        );
        // The path is disk-agnostic, so the row itself must not be rewritten.
        $this->assertDatabaseHas('photos', ['id' => $photo->id, 'path' => 'documents/face1.jpg']);
    }

    public function test_it_is_idempotent_and_does_not_duplicate_on_a_second_run(): void
    {
        Storage::disk(self::FROM)->put('documents/a.jpg', 'BYTES-A');
        $this->photo('documents/a.jpg');

        $this->run_()->assertExitCode(0);
        // Second run with the source already emptied - must skip, not fail, not duplicate.
        $this->run_()->assertExitCode(0);

        $this->assertSame('BYTES-A', Storage::disk(self::TO)->get('documents/a.jpg'));
        $this->assertSame(1, Photo::query()->count());
    }

    public function test_dry_run_reports_but_moves_nothing(): void
    {
        Storage::disk(self::FROM)->put('documents/dry.jpg', 'DRY-BYTES');
        $this->photo('documents/dry.jpg');

        $this->run_(['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk(self::FROM)->exists('documents/dry.jpg'),
            'A dry run must leave the source untouched.');
        $this->assertFalse(Storage::disk(self::TO)->exists('documents/dry.jpg'),
            'A dry run must not write to the target.');
    }

    public function test_a_row_whose_file_is_on_neither_disk_is_reported_and_left_alone(): void
    {
        // A photos row pointing at a file that is already gone - a stale row from an earlier bug.
        $this->photo('documents/ghost.jpg');

        $this->run_()->assertExitCode(0);

        // A row with no file must survive so an operator can see it, never be silently dropped.
        $this->assertDatabaseHas('photos', ['path' => 'documents/ghost.jpg']);
    }

    public function test_it_refuses_to_run_when_source_and_target_are_the_same(): void
    {
        Storage::disk(self::FROM)->put('documents/same.jpg', 'BYTES');
        $this->photo('documents/same.jpg');

        $this->artisan('kyc:migrate-disk', [
            '--from' => self::FROM,
            '--to' => self::FROM,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertTrue(Storage::disk(self::FROM)->exists('documents/same.jpg'),
            'A same-disk run must not delete anything.');
    }

    public function test_it_refuses_an_undefined_disk_before_touching_anything(): void
    {
        Storage::disk(self::FROM)->put('documents/u.jpg', 'BYTES');
        $this->photo('documents/u.jpg');

        $this->artisan('kyc:migrate-disk', [
            '--from' => self::FROM,
            '--to' => 'no_such_disk',
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertTrue(Storage::disk(self::FROM)->exists('documents/u.jpg'));
    }

    public function test_every_kyc_type_is_migrated_not_just_face_id(): void
    {
        $user = User::factory()->create();

        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $index => $type) {
            $path = "verifications/{$type}/doc{$index}.jpg";
            Storage::disk(self::FROM)->put($path, "BYTES-{$type}");

            Photo::create(['user_id' => $user->id, 'type' => $type, 'path' => $path]);
        }

        $this->run_()->assertExitCode(0);

        foreach (['face_id', 'back_id', 'license', 'mechanic_card'] as $index => $type) {
            $path = "verifications/{$type}/doc{$index}.jpg";
            $this->assertSame("BYTES-{$type}", Storage::disk(self::TO)->get($path), "{$type} was not migrated");
            $this->assertFalse(Storage::disk(self::FROM)->exists($path), "{$type} was left on the public disk");
        }
    }

    public function test_it_handles_an_empty_table_without_error(): void
    {
        $this->run_()
            ->expectsOutputToContain('No KYC documents')
            ->assertExitCode(0);
    }
}
