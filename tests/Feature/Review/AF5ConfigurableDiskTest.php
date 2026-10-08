<?php

namespace Tests\Feature\Review;

use App\Http\Controllers\API\Staff\StaffDocumentController;
use App\Models\Photo;
use App\Models\User;
use App\Services\File\FileUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AF-5 - uploads resolve their disk from config instead of a hard-coded 'public'.
 *
 * THE DEFECT THIS FIXES.
 *
 * `DocumentController::store` already wrote to `config('filesystems.documents_disk')`, while
 * `StaffDocumentController::download` read from a literal `Storage::disk('public')`. Decision un1
 * (MinIO) documented "set DOCUMENTS_DISK=minio to move identity documents off the public disk" as a
 * one-line deploy change. Doing exactly that would have written every document to a disk the reader
 * never looks at - a 404 for all of them, with an audit record claiming the exposure was closed.
 *
 * So the switch was half-wired, and the documented instruction was actively dangerous.
 *
 * WHAT THESE TESTS PROVE.
 *
 * The default must NOT change - every default here is `public`, so nothing moves until an operator
 * sets an env var. That is what makes the change safe to deploy today.
 *
 * The second test is the one that matters: point the config at a different disk and prove the file
 * actually lands there. Without it, a test that only exercised the default could not tell a
 * configurable disk from a hard-coded one - the class of test that lets a half-wired switch pass.
 */
class AF5ConfigurableDiskTest extends TestCase
{
    use RefreshDatabase;

    private FileUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FileUploadService::class);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('avatar.png', 40, 40);
    }

    /**
     * @test
     */
    public function uploads_stay_public_by_default_but_identity_documents_do_not(): void
    {
        // SPLIT, deliberately (RV-01, owner instruction 2026-10-08).
        //
        // This assertion used to require BOTH defaults to be `public`, on the deployment-safety
        // argument that a non-public default breaks uploads on a host with no object storage. That
        // argument was sound for *uploads* and wrong for *identity documents*: profile photos and
        // chat images are meant to be public, whereas a national ID being readable by anyone who
        // guesses `/storage/<path>` is the defect RV-01 exists to close.
        //
        // The two concerns now have different defaults, which is why they are two keys at all.
        // `documents_disk` defaults to the PRIVATE `local` disk; `kyc:migrate-disk` moves the
        // already-written files and `StaffDocumentController` falls back so nothing 404s.
        $this->assertSame('public', config('filesystems.uploads_disk'));
        $this->assertSame('local', config('filesystems.documents_disk'));

        // `local` must actually be private, or this change buys nothing. The config value is
        // already the RESOLVED absolute root (config/filesystems.php calls storage_path() at load
        // time), so the check is containment, not the literal helper name.
        $this->assertSame('local', config('filesystems.disks.local.driver'));

        $normalise = static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/');

        $this->assertStringNotContainsString(
            $normalise(config('filesystems.disks.public.root')),
            $normalise(config('filesystems.disks.local.root')),
            'the private root must not sit underneath the web-reachable public root'
        );
    }

    /**
     * @test
     */
    public function an_upload_lands_on_the_configured_disk_not_a_hard_coded_one(): void
    {
        Storage::fake('minio-probe');
        Storage::fake('public');
        config(['filesystems.uploads_disk' => 'minio-probe']);

        $user = User::factory()->create();
        $path = $this->service->uploadProfilePhoto($this->png(), $user->id);

        Storage::disk('minio-probe')->assertExists($path);

        // The specific failure this replaces: the file being written somewhere the read side
        // never looks, which is a 404 with no other symptom.
        $this->assertFalse(
            Storage::disk('public')->exists($path),
            'the file must not also sit on the old hard-coded disk'
        );
    }

    /**
     * @test
     */
    public function the_default_path_still_lands_on_public(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $path = $this->service->uploadProfilePhoto($this->png(), $user->id);

        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('profiles/profile_photo/', $path);
    }

    /**
     * @test
     */
    public function reads_follow_the_same_disk_as_the_write_that_created_the_file(): void
    {
        Storage::fake('minio-probe');
        config(['filesystems.uploads_disk' => 'minio-probe']);

        $user = User::factory()->create();
        $path = $this->service->uploadProfilePhoto($this->png(), $user->id);

        // exists() and size() resolved the disk separately from the write. If either still named
        // 'public', the pair would disagree and every read would 404.
        $this->assertTrue($this->service->exists($path), 'exists() must look where the write went');
        $this->assertStringNotContainsString(
            ' 0 B',
            $this->service->getReadableSize($path),
            'getReadableSize() must look where the write went, not report an empty file'
        );
    }

    /**
     * @test
     */
    public function delete_removes_the_file_from_the_configured_disk(): void
    {
        Storage::fake('minio-probe');
        config(['filesystems.uploads_disk' => 'minio-probe']);

        $user = User::factory()->create();
        $path = $this->service->uploadProfilePhoto($this->png(), $user->id);

        $this->assertTrue($this->service->delete($path));
        Storage::disk('minio-probe')->assertMissing($path);
    }

    /**
     * @test
     *
     * @group af5-needle
     */
    public function the_staff_document_reader_follows_the_disk_the_document_was_written_to(): void
    {
        // This is the test that pins the half-wired switch itself. It writes a KYC document the way
        // `DocumentController::store` does - onto `documents_disk` - and then serves it through the
        // staff reader. With the reader pinned to a hard-coded 'public' this 404s, because the file
        // was never written there.
        //
        // It was added AFTER a needle proved that nothing in the suite covered this: restoring the
        // hard-coded read left 617 tests / 5 errors / 9 failures completely unchanged.
        Storage::fake('minio-probe');
        config(['filesystems.documents_disk' => 'minio-probe']);

        $user = User::factory()->create();
        $photo = Photo::create([
            'user_id' => $user->id,
            'type' => 'face_id',
            'path' => 'documents/probe.jpg',
        ]);

        Storage::disk('minio-probe')->put('documents/probe.jpg', 'fake-jpeg-bytes');

        $response = app(StaffDocumentController::class)
            ->show(Request::create("/api/staff/documents/{$photo->id}", 'GET'), $photo->id);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'the staff reader must read from the same disk the write used'
        );
        $this->assertSame('fake-jpeg-bytes', $response->getContent());
    }

    /**
     * @test
     */
    public function a_document_absent_from_the_configured_disk_still_404s_rather_than_leaking(): void
    {
        // The fix must not turn the "missing file" case into a different failure. A 404 is correct
        // and must stay a 404; leaking a 500 or a different body would be a regression.
        Storage::fake('minio-probe');
        config(['filesystems.documents_disk' => 'minio-probe']);

        $user = User::factory()->create();
        $photo = Photo::create([
            'user_id' => $user->id,
            'type' => 'license',
            'path' => 'documents/never-written.jpg',
        ]);

        $response = app(StaffDocumentController::class)
            ->show(Request::create("/api/staff/documents/{$photo->id}", 'GET'), $photo->id);

        $this->assertSame(404, $response->getStatusCode());
    }
}
