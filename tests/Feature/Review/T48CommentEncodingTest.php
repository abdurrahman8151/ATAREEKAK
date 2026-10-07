<?php

namespace Tests\Feature\Review;

use Tests\TestCase;

/**
 * T4-8: source COMMENTS must not hold double-encoded (Windows-1252 mojibake) text.
 *
 * WHAT THE FAULT IS. A UTF-8 file was read as Windows-1252 and written back as UTF-8, more than
 * once. `// elapsed < 30% <8 mangled characters> full refund` is U+2192 RIGHTWARDS ARROW after two
 * such passes - the corruption can be several passes DEEP, which is why a one-call fix does not
 * converge on it. The damage is not cosmetic: `AGENTS.md` records that "em-dashes and other
 * non-ASCII characters in anchors have repeatedly failed to match", and this is the cause - an
 * anchor written with a real arrow cannot match a comment holding eight corrupted characters.
 *
 * WHY ONLY COMMENTS ARE COUNTED. A PHP string literal is not a comment token, so this test cannot
 * see one. That is deliberate, and it is also the limit of T4-8: `app/` ALSO holds mojibake in
 * user-visible Arabic message literals, which is a real defect but a DIFFERENT one, because
 * repairing it changes text the Flutter client displays. Do not widen this ratchet to strings
 * without filing that first.
 *
 * THE DETECTOR IS THE INVERSE OF THE FAULT, NOT A LIST OF BAD CHARACTERS. `â` also occurs in
 * perfectly ordinary text, so a character blacklist would be either wrong or all false positives.
 * Instead: map each character of a run back to the single Windows-1252 byte it would have been
 * read from, and see whether those bytes form valid UTF-8. If they do, the run was UTF-8 read as
 * Windows-1252, and repeating that undoes it. A run is reported only when the undo REACHES A
 * FIXED POINT, so legitimate text is untouched by construction - Arabic, box drawing, emoji and a
 * real `—` or `→` either have no Windows-1252 byte at all, or their byte is not valid UTF-8.
 */
class T48CommentEncodingTest extends TestCase
{
    /** 2026-10-08: 226 runs across 9 files repaired in T4-8. Zero. Kept as a permanent gate. */
    private const BASELINE = 0;

    /** @return array<string, string> character => the Windows-1252 byte it came from */
    private static function cp1252Table(): array
    {
        static $table = null;
        if ($table !== null) {
            return $table;
        }
        $table = [];
        for ($b = 0x80; $b <= 0xFF; $b++) {
            $c = @mb_convert_encoding(chr($b), 'UTF-8', 'Windows-1252');
            // Undefined slots (0x81, 0x8D, 0x8F, 0x90, 0x9D) decode to a single '?'
            // substitution, so only genuine multi-byte characters enter the table.
            if (strlen($c) > 1 && mb_check_encoding($c, 'UTF-8')) {
                $table[$c] = $b;
            }
        }

        return $table;
    }

    private static function characters(string $s): array
    {
        $out = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);

        return $out === false ? [] : $out;
    }

    private static function cp1252Byte(string $ch): ?int
    {
        return self::cp1252Table()[$ch] ?? null;
    }

    private static function cp1252Char(int $b): string
    {
        // The five undefined Windows-1252 slots. mbstring substitutes '?' for them; a faithful
        // reader yields the C1 control of the same number, which is what the inverse refuses.
        static $undefined = [0x81, 0x8D, 0x8F, 0x90, 0x9D];
        if (in_array($b, $undefined, true)) {
            return mb_chr($b, 'UTF-8');
        }

        return @mb_convert_encoding(chr($b), 'UTF-8', 'Windows-1252');
    }

    /**
     * ONE forward pass - read these BYTES as Windows-1252 and write the result back as UTF-8.
     *
     * It must run over bytes, not characters: the corruption happens when the UTF-8 bytes of a
     * character are read in the wrong encoding, so a character with no Windows-1252 equivalent of
     * its own (U+2192 has none) is still perfectly corruptible.
     */
    private static function corruptOnce(string $text): string
    {
        $out = '';
        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $out .= self::cp1252Char(ord($text[$i]));
        }

        return $out;
    }

    /** One inverse pass, or null when no further pass is possible (which IS the fixed point). */
    private static function unwrap(string $run): ?string
    {
        $bytes = '';
        foreach (self::characters($run) as $ch) {
            $b = self::cp1252Byte($ch);
            if ($b === null) {
                return null;
            }
            $bytes .= chr($b);
        }
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            return null;
        }

        return $bytes;
    }

    private static function isMojibake(string $run): bool
    {
        $current = $run;
        $changed = false;
        for ($pass = 0; $pass < 1000; $pass++) {
            $next = self::unwrap($current);
            if ($next === null || $next === $current) {
                // A C1 control is what an undefined Windows-1252 slot leaves behind. That
                // corruption is unrecoverable, so it is not this test's business.
                return $changed && ! preg_match('/[\x{0080}-\x{009F}]/u', $current);
            }
            $current = $next;
            $changed = true;
        }

        return false;
    }

    /** True if ANY run of this text is mojibake. This is the detector's real entry point. */
    private static function holdsMojibake(string $text): bool
    {
        $run = '';
        foreach (array_merge(self::characters($text), [null]) as $ch) {
            if ($ch !== null && self::cp1252Byte($ch) !== null) {
                $run .= $ch;

                continue;
            }
            if ($run !== '' && self::isMojibake($run)) {
                return true;
            }
            $run = '';
        }

        return false;
    }

    /** @return array<string, string> file => the token text, for every comment holding mojibake */
    private function findings(): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $source = file_get_contents($path);
            if ($source === false || ! preg_match('/[\x80-\xFF]/', $source)) {
                continue;
            }

            foreach (token_get_all($source) as $token) {
                if (! is_array($token) || ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT)) {
                    continue;   // a string literal cannot be reached from here
                }
                if ($this->tokenHoldsMojibake($token[1])) {
                    $found[str_replace('\\', '/', substr($path, strlen(base_path()) + 1))] = $token[2];
                }
            }
        }

        ksort($found);

        return $found;
    }

    private function tokenHoldsMojibake(string $text): bool
    {
        return self::holdsMojibake($text);
    }

    public function test_no_comment_in_app_holds_recoverable_mojibake(): void
    {
        $found = $this->findings();

        $this->assertLessThanOrEqual(
            self::BASELINE,
            count($found),
            'Recoverable Windows-1252 mojibake in comments (baseline '.count($found).'): '
            .json_encode($found, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * The non-vacuity proof. With a baseline of zero a broken detector would pass forever, so the
     * detector is run against text that is known one way or the other. The corrupt samples are
     * GENERATED by the forward transform rather than hand-written as hex, because hand-written
     * mojibake bytes are easy to get subtly wrong - which is exactly how the first draft of this
     * test managed to "prove" that real mojibake was clean.
     *
     * @dataProvider knownSamples
     */
    public function test_the_detector_separates_corrupt_from_clean(string $sample, bool $expected, string $why): void
    {
        $this->assertSame($expected, self::holdsMojibake($sample), $why);
    }

    /** @return array<string, array{0: string, 1: bool, 2: string}> */
    public static function knownSamples(): array
    {
        $samples = [];

        $corrupt = [
            'U+2192 RIGHTWARDS ARROW, 1 pass' => ["\u{2192}", 1],
            'U+2192 RIGHTWARDS ARROW, 2 passes' => ["\u{2192}", 2],
            'U+2014 EM DASH, 1 pass' => ["\u{2014}", 1],
            'U+2019 RIGHT SINGLE QUOTE, 1 pass' => ["\u{2019}", 1],
            'U+2265 GREATER-THAN OR EQUAL, 2 passes' => ["\u{2265}", 2],
            'U+2264 LESS-THAN OR EQUAL, 1 pass' => ["\u{2264}", 1],
            'U+00D7 MULTIPLICATION SIGN, 1 pass' => ["\u{00D7}", 1],
            'U+00E9 e-acute, 1 pass' => ["\u{00E9}", 1],
            'U+2500 BOX DRAWING, 1 pass' => ["\u{2500}", 1],
            'a whole phrase, 2 passes' => ["\u{2192} revoke BOTH \u{2014} now", 2],
        ];
        foreach ($corrupt as $label => [$clean, $passes]) {
            $damaged = $clean;
            for ($i = 0; $i < $passes; $i++) {
                $damaged = self::corruptOnce($damaged);
            }
            $samples['corrupt: '.$label] = [$damaged, true, 'must be detected as mojibake'];
        }

        $clean = [
            'U+2014 EM DASH' => "\u{2014}",
            'U+2192 RIGHTWARDS ARROW' => "\u{2192}",
            'U+2500 BOX DRAWINGS LIGHT HORIZONTAL' => "\u{2500}",
            'U+2550 BOX DRAWINGS DOUBLE HORIZONTAL' => "\u{2550}",
            'U+2265 GREATER-THAN OR EQUAL' => "\u{2265}",
            'U+2713 CHECK MARK' => "\u{2713}",
            'U+274C CROSS MARK' => "\u{274C}",
            'U+1F697 AUTOMOBILE (emoji)' => "\u{1F697}",
            'U+00A7 SECTION SIGN' => "\u{00A7}",
            'U+00D7 MULTIPLICATION SIGN' => "\u{00D7}",
            'U+00E9 e-acute' => "\u{00E9}",
            'Arabic text (UTF-8)' => "\u{062A}\u{0645}\u{0634}",
            'a phrase mixing box drawing and an arrow' => "\u{2500}\u{2500} Caching \u{2192} summary \u{2550}",
        ];
        foreach ($clean as $label => $text) {
            $samples['clean: '.$label] = [$text, false, 'must NOT be flagged'];
        }

        return $samples;
    }

    /**
     * Ties the detector to the byte sequence actually on disk, so a future change to the
     * transform cannot quietly redefine what this ratchet means. These are the bytes of
     * `app/Services/Ride/RideService.php` line 190 as they stood before T4-8.
     */
    public function test_the_transform_reproduces_the_bytes_found_on_disk(): void
    {
        $fromDisk = "\xC3\x83\xC2\xA2\xC3\xA2\xE2\x82\xAC\xC2\xA0\xC3\xA2\xE2\x82\xAC\xE2\x84\xA2";

        $this->assertSame($fromDisk, self::corruptOnce(self::corruptOnce("\u{2192}")));
        $this->assertTrue(self::isMojibake($fromDisk));
        $this->assertSame("\u{2192}", self::unwrap(self::unwrap($fromDisk)));
    }

    public function test_the_ratchet_cannot_see_a_string_literal(): void
    {
        // A string literal holding corrupt bytes is invisible to this ratchet. That is the
        // documented limit of T4-8, asserted here so nobody widens the scope by accident.
        $source = "<?php\n\$a = \"".self::corruptOnce(self::corruptOnce("\u{2192}"))."\";\n";
        $comments = 0;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $comments++;
            }
        }

        $this->assertSame(0, $comments, 'a string literal must not tokenise as a comment');
    }
}
