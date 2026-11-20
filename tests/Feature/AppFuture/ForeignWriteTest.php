<?php

namespace Tests\Feature\AppFuture;

use ReflectionClass;
use Tests\Support\Boundary\ForeignWriteScanner;
use Tests\TestCase;

/**
 * Stage 4 A1: the cross-module WRITE ratchet.
 *
 * The other ownership ratchets police what a module NAMES. This one polices what it WRITES, because a module can
 * write another module's row through a contract it is allowed to name - which is how
 * `BookingCharge::snapshotBookingCharge()` survived every green ratchet while force-filling Money's snapshot onto a
 * Ride-owned booking row.
 *
 * Shrink-only: the declared census must equal the measurement, so a new foreign write fails and a fix shrinks the
 * list. The needle is a SELF-TEST of the detector, loaded from `tests/Fixtures/foreign-writes/*.php.txt` - real
 * source text that the determinism ratchet does not scan (it only scans test classes), and never a patch to a
 * tracked production file.
 */
final class ForeignWriteTest extends TestCase
{
    /**
     * The census. Shrink-only - remove a line when its write is fixed; never add one without the owner's ruling.
     *
     * @var array<string, array<int, string>>
     */
    private const FOREIGN_WRITES = [
        // EMPTY as of stage 4 A2: Money returns the money snapshot as a DTO and Ride, which owns `bookings`,
        // persists it. An empty census is only meaningful because the coverage assertion below and the fixture
        // needles keep the detector honest - a scanner that stopped seeing anything would fail those two.
    ];

    /** The scan must actually cover the module tree, or an empty census would be vacuous. */
    private const MIN_SCANNED_FILES = 250;

    private function scanner(): ForeignWriteScanner
    {
        $reflection = new ReflectionClass(ModelOwnershipTest::class);

        return new ForeignWriteScanner($reflection->getConstant('TABLE_OWNERS'));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../../Fixtures/foreign-writes/'.$name.'.php.txt');
    }

    /** @test */
    public function the_foreign_write_census_is_exactly_the_declared_list(): void
    {
        $measured = $this->scanner()->scanTree(app_path());

        $this->assertSame(
            self::FOREIGN_WRITES,
            $measured,
            "The cross-module write census changed.\nMeasured: ".json_encode($measured, JSON_PRETTY_PRINT)
            ."\nDeclared: ".json_encode(self::FOREIGN_WRITES, JSON_PRETTY_PRINT).
            "\nThe owner of a row is the only writer of it: return a DTO and let the owner persist it, or (only with"
            .' an owner ruling) declare the new site here in the same commit.'
        );
    }

    /** @test */
    public function the_scan_covers_the_module_tree(): void
    {
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Modules'), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (str_ends_with($file->getPathname(), '.php')) {
                $files++;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_SCANNED_FILES,
            $files,
            'The foreign-write scan found fewer module files than exist - the scan is broken, not the codebase.'
        );
    }

    /** @test */
    public function the_detector_catches_a_new_foreign_write_and_ignores_reads(): void
    {
        $scanner = new ForeignWriteScanner(['bookings' => 'Ride', 'wallets' => 'Money']);

        // needle 1: a Money file writing Ride's row through the view it is allowed to name
        $this->assertContains(
            '$booking->forceFill() writes Ride\'s row through BookingView',
            $scanner->scanSource($this->fixture('rogue-view-write'), 'Money'),
            'the detector missed a write through BookingView'
        );

        // needle 2: a raw table write on a foreign table
        $this->assertNotEmpty(
            $scanner->scanSource($this->fixture('rogue-table-write'), 'Money'),
            'the detector missed a raw table write on Ride\'s table'
        );

        // needle 3: a static write on a foreign model
        $this->assertNotEmpty(
            $scanner->scanSource($this->fixture('rogue-model-write'), 'Money'),
            'the detector missed a static write on Ride\'s model from Money'
        );

        // NEGATIVE CONTROL: reads must not be reported, or the ratchet would fire on every screen
        $this->assertSame(
            [],
            $scanner->scanSource($this->fixture('reader-only'), 'Money'),
            'reads must not be reported as writes'
        );

        // NEGATIVE CONTROL: the owner writing its own row is legal
        $this->assertSame(
            [],
            $scanner->scanSource($this->fixture('owner-write'), 'Ride'),
            'the owner writing its own row must not be reported'
        );
    }
}
