<?php

namespace Tests\Feature\Review;

use Tests\TestCase;

/**
 * `docs/audit/BACKLOG.md` is the STATUS AUTHORITY: the next-task rule in `AGENTS.md` reads its
 * section-2 table, and `AGENTS.md` itself says BACKLOG.md wins when documents disagree.
 *
 * R2 sec 113 - why this test exists. Seven of its 115 rows had the wrong number of cells against an
 * 8-column header, and the damage was not cosmetic:
 *
 *  - Row 110 (AF-7b) carried a NINTH cell, so a parser reading field 4 as `Status` saw `OPEN` on a
 *    row whose own Evidence cell says "Row closed". That made it the ONLY `OPEN` row with
 *    `Blocked by = none` - i.e. the one row the rule could legally select - while three sessions in a
 *    row reported "none unblocked".
 *  - Rows 35, 44, 45 and 64 had their Evidence cells split by a stray delimiter (64 had three).
 *  - Rows 110, 111 and 112 had `ID` and `Title` transposed by the same splice.
 *  - Row 112 had swallowed the entire `## 3. Commit verification` HEADING into its last cell, so
 *    section 3 had no heading of its own and rows 113-115 sat below a heading that should have ended
 *    the table.
 *  - Row 109's Evidence cell contained a literal `|` inside a code span, which also breaks the row
 *    in rendered markdown, not only for a parser.
 *
 * Sec 100 recorded that this file had already been corrupted once; sec 111 that its columns went
 * stale twice. A document that decides what work happens next needs a shape, not just a review.
 *
 * WHAT THIS DOES NOT DO: it does not judge whether a `Status` is TRUE. That is what the audit record
 * and the code are for. It only proves the table is addressable by its own header - which is the
 * precondition for reading anything out of it.
 */
class BacklogTableShapeTest extends TestCase
{
    private const HEADER = '| Order | ID | Title | Pri | Status | Blocked by (owner decision #) | Aliases | Evidence (file section / commit) |';

    private const EXPECTED_COLUMNS = 8;

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $path = base_path('docs/audit/BACKLOG.md');

        $this->assertFileExists($path, 'BACKLOG.md is the status authority; the ratchet needs it');

        return preg_split('/\r\n|\n|\r/', (string) file_get_contents($path)) ?: [];
    }

    /**
     * The detector, extracted so the self-test below can feed it a known-bad row.
     *
     * A cell count ignores the leading and trailing delimiter: `| a | b |` splits into 3 parts, of
     * which the first and last are empty, so a well-formed row of N columns yields N + 1.
     *
     * @return array{count: int, cells: list<string>}
     */
    private function splitRow(string $line): array
    {
        $cells = array_map('trim', explode('|', trim($line, " \t|")));

        return ['count' => count($cells), 'cells' => $cells];
    }

    private function isTableRow(string $line): bool
    {
        return (bool) preg_match('/^\|\s*\d+\s*\|/', $line);
    }

    /**
     * ONLY the section-2 backlog table, addressed from the headings rather than by line number.
     *
     * Scoped deliberately: this file carries at least two other pipe tables (the owner-decision index
     * in section 1a and the coverage table in section 7), and `R2 sec 64.3` recorded a ratchet that was
     * over-broad hiding real defects. A shape check that fires on someone else's table is noise that
     * gets switched off. The first run of this test did exactly that and was corrected here.
     *
     * @return array<string, string> line number as written in the file => the row
     */
    private function backlogRows(): array
    {
        $lines = $this->lines();
        $start = null;
        $end = count($lines);

        foreach ($lines as $i => $line) {
            if ($start === null && $line === '## 2. The backlog') {
                $start = $i;

                continue;
            }
            if ($start !== null && preg_match('/^##\s/', $line)) {
                $end = $i;

                break;
            }
        }

        $this->assertNotNull($start, 'section 2 heading not found');

        $rows = [];
        for ($i = (int) $start; $i < $end; $i++) {
            if ($this->isTableRow($lines[$i])) {
                $rows[(string) ($i + 1)] = $lines[$i];
            }
        }

        return $rows;
    }

    public function test_the_header_row_declares_the_column_count_the_body_uses(): void
    {
        $header = $this->splitRow(self::HEADER);

        $this->assertSame(
            self::EXPECTED_COLUMNS,
            $header['count'],
            'if the header changes, every assertion below must be re-derived - do not just bump the constant'
        );
    }

    public function test_every_row_of_the_backlog_table_has_exactly_the_header_column_count(): void
    {
        $offenders = [];

        foreach ($this->backlogRows() as $lineNo => $line) {
            $parsed = $this->splitRow($line);
            if ($parsed['count'] !== self::EXPECTED_COLUMNS) {
                $offenders[] = sprintf('line %s: %d cells, expected %d', $lineNo, $parsed['count'], self::EXPECTED_COLUMNS);
            }
        }

        $this->assertGreaterThan(100, count($this->backlogRows()), 'the scan must actually be finding the table');
        $this->assertSame([], $offenders, "BACKLOG.md rows must match the header's column count.\n".implode("\n", $offenders));
    }

    public function test_no_order_number_is_duplicated(): void
    {
        $seen = [];

        foreach ($this->backlogRows() as $lineNo => $line) {
            $order = trim(explode('|', $line)[1]);
            if (array_key_exists($order, $seen)) {
                $this->fail("Order {$order} is used twice: lines {$seen[$order]} and {$lineNo}");
            }
            $seen[$order] = $lineNo;
        }

        $this->assertNotEmpty($seen);
    }

    public function test_no_heading_is_swallowed_into_a_table_row(): void
    {
        // This is the exact shape of the defect that ate section 3: the heading text ended up inside
        // the last cell of a row instead of on a line of its own.
        foreach ($this->backlogRows() as $lineNo => $line) {
            $this->assertDoesNotMatchRegularExpression(
                '/##\s+\d/',
                $line,
                "line {$lineNo} swallows a section heading into a table row"
            );
        }
    }

    public function test_the_numbered_sections_are_present_and_in_order(): void
    {
        $found = [];

        foreach ($this->lines() as $line) {
            if (preg_match('/^##\s+(\d+)\./', $line, $m)) {
                $found[] = (int) $m[1];
            }
        }

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $found, 'sections must be present exactly once each, in order');
    }

    /**
     * The detector proves itself: a row with an extra delimiter must be reported, or this whole file
     * would pass on a table it cannot actually read. `R2 sec 64.1` recorded that a ratchet which was
     * over-broad hid real defects, so the failure mode to guard is a detector that is too NARROW.
     */
    public function test_the_detector_reports_a_row_with_a_stray_delimiter(): void
    {
        $good = '| 1 | ID | Title | P1 | OPEN | none | alias | evidence |';
        $this->assertSame(self::EXPECTED_COLUMNS, $this->splitRow($good)['count']);

        // Exactly the row-110 shape: a ninth cell injected between Status and Blocked by.
        $bad = '| 1 | ID | Title | P1 | OPEN | VERIFIED FIX | none | alias | evidence |';
        $this->assertSame(self::EXPECTED_COLUMNS + 1, $this->splitRow($bad)['count']);

        // And the shape of AF-5, which had three Evidence cells instead of one.
        $worse = '| 1 | ID | Title | P0 | PARTIAL | gate | a | b | c | d |';
        $this->assertSame(self::EXPECTED_COLUMNS + 2, $this->splitRow($worse)['count']);
    }
}
