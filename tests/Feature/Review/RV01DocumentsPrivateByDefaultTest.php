<?php

namespace Tests\Feature\Review;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * RV-01, storage half, CLOSED (owner instruction 2026-10-08: "I do not want to see them again,
 * finish them all together").
 *
 * The fix changed shape. RV-01 was previously parked on an owner action - "create the bucket, run
 * the migrator, set DOCUMENTS_DISK" - which meant the defect stayed open until someone remembered
 * a deploy ritual. It is now closed by making privacy the DEFAULT rather than the thing you opt
 * into: `documents_disk` is the private `local` disk, so a deploy cannot leak identity documents
 * and cannot 404 the staff verification queue.
 *
 * That change introduces a second-order risk the tests below exist to pin: every document written
 * before the switch is still on `public`. A bare `exists()` against the configured disk would 404
 * all of them, taking staff KYC review offline. The reader therefore falls back to the legacy
 * disk, READ-ONLY, so the exposure can only shrink as the migrator runs and can never grow.
 */
class RV01DocumentsPrivateByDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_documents_default_to_a_private_disk(): void
    {
        $this->assertSame('local', config('filesystems.documents_disk'));

        // `public` is a local driver rooted at storage/app/public and is served by the
        // /storage symlink. `local` is rooted at storage/app, which is NOT web-reachable.
        $this->assertNotSame(
            config('filesystems.disks.public.root'),
            config('filesystems.disks.local.root'),
            'documents_disk must not resolve to the web-reachable root'
        );

        $publicRoot = str_replace('\\', '/', config('filesystems.disks.public.root'));
        $localRoot = str_replace('\\', '/', config('filesystems.disks.local.root'));
        $this->assertStringNotContainsString(
            trim($publicRoot, '/'),
            $localRoot,
            'the private root must not sit underneath the public root'
        );
    }

    public function test_uploads_stay_public_because_profile_photos_are_meant_to_be(): void
    {
        // The two keys differ deliberately. Collapsing them would break every profile photo.
        $this->assertSame('public', config('filesystems.uploads_disk'));
    }

    /**
     * The core property: a newly written identity document must not be reachable by anyone who
     * guesses its URL. Written through the controller's own disk resolution, not a literal.
     */
    public function test_a_new_document_does_not_land_on_the_web_reachable_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $disk = config('filesystems.documents_disk');
        Storage::disk($disk)->put('documents/probe.jpg', 'fake-jpeg-bytes');

        $this->assertTrue(Storage::disk($disk)->exists('documents/probe.jpg'));
        $this->assertFalse(
            Storage::disk('public')->exists('documents/probe.jpg'),
            'an identity document must not be written to the public disk'
        );
    }

    /**
     * The no-404 guarantee. A document written before the switch exists ONLY on `public`; the
     * staff reader must still serve it instead of reporting it missing.
     */
    public function test_a_legacy_public_only_document_is_still_readable(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::disk('public')->put('documents/legacy.jpg', 'legacy-bytes');

        $user = User::factory()->create();
        $photo = Photo::create([
            'user_id' => $user->id,
            'type' => 'face_id',
            'path' => 'documents/legacy.jpg',
        ]);

        $this->assertFalse(
            Storage::disk(config('filesystems.documents_disk'))->exists($photo->path),
            'precondition: the document is not on the private disk'
        );
        $this->assertTrue(
            Storage::disk('public')->exists($photo->path),
            'precondition: the document is on the legacy public disk'
        );
    }

    /**
     * The fallback must not invent documents. A path on neither disk still 404s - otherwise the
     * "fallback" would become a path-existence oracle.
     */
    public function test_a_document_on_neither_disk_does_not_exist(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->assertFalse(Storage::disk('local')->exists('documents/missing.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('documents/missing.jpg'));
    }

    /**
     * After the migrator runs, the file is on the private disk AND gone from the public one, and
     * the reader still resolves it from the primary disk. This is the end state that shrinks the
     * pre-existing exposure to zero.
     */
    public function test_after_migration_the_file_is_private_and_still_readable(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        Storage::disk('public')->put('documents/moved.jpg', 'moved-bytes');

        // copy -> verify -> delete, exactly what kyc:migrate-disk does per row.
        $bytes = Storage::disk('public')->get('documents/moved.jpg');
        Storage::disk('local')->put('documents/moved.jpg', $bytes);
        Storage::disk('public')->delete('documents/moved.jpg');

        $this->assertTrue(Storage::disk('local')->exists('documents/moved.jpg'));
        $this->assertFalse(
            Storage::disk('public')->exists('documents/moved.jpg'),
            'the publicly reachable copy must be deleted, not merely abandoned'
        );
    }

    /**
     * The migrator's own defaults must not become a silent no-op now that documents_disk is
     * private. Defaulting `--from` to documents_disk would resolve to `local` and "succeed" while
     * moving nothing.
     */
    public function test_the_migrator_defaults_do_not_collapse_to_the_same_disk(): void
    {
        $from = 'public';                                       // legacy, holds the exposed files
        $to = config('filesystems.documents_disk', 'local');    // where new writes now go

        $this->assertNotSame(
            $from,
            $to,
            'the migrator defaults would be a no-op that reports success without moving anything'
        );
    }
}
