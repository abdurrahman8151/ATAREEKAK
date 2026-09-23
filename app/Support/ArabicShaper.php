<?php

namespace App\Support;

/**
 * Reshapes Arabic text into the correct joined (contextual) presentation
 * forms before handing HTML to dompdf.
 *
 * dompdf/DejaVu Sans render Arabic glyph-by-glyph in isolated form — letters
 * that should visually connect ("مرحبا") come out as disconnected letters
 * ("م ر ح ب ا") because dompdf does not perform Arabic text shaping itself.
 * This maps each base letter to its isolated/initial/medial/final Unicode
 * Presentation-Forms codepoint (all present in DejaVu Sans) based on its
 * neighbours, plus the mandatory lam-alef ligatures.
 *
 * Reading order (bidi) is unaffected — dompdf already reorders RTL runs
 * correctly; only glyph selection was wrong.
 */
final class ArabicShaper
{
    /** base codepoint => [isolated, final, initial?, medial?] */
    private const FORMS = [
        0x0621 => [0xFE80, null,   null,   null],
        0x0622 => [0xFE81, 0xFE82, null,   null],
        0x0623 => [0xFE83, 0xFE84, null,   null],
        0x0624 => [0xFE85, 0xFE86, null,   null],
        0x0625 => [0xFE87, 0xFE88, null,   null],
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C],
        0x0627 => [0xFE8D, 0xFE8E, null,   null],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
        0x0629 => [0xFE93, 0xFE94, null,   null],
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],
        0x062F => [0xFEA9, 0xFEAA, null,   null],
        0x0630 => [0xFEAB, 0xFEAC, null,   null],
        0x0631 => [0xFEAD, 0xFEAE, null,   null],
        0x0632 => [0xFEAF, 0xFEB0, null,   null],
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4],
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0],
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC],
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4],
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
        0x0648 => [0xFEED, 0xFEEE, null,   null],
        0x0649 => [0xFEEF, 0xFEF0, 0xFBE8, 0xFBE9],
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
    ];

    /** Letters that only ever connect to the previous letter, never the next. */
    private const RIGHT_JOINING = [
        0x0622, 0x0623, 0x0624, 0x0625, 0x0627, 0x0629, 0x062F, 0x0630, 0x0631, 0x0632, 0x0648, 0x0649,
    ];

    /** Letters that connect on both sides when surrounded by joinable neighbours. */
    private const DUAL_JOINING = [
        0x0626, 0x0628, 0x062A, 0x062B, 0x062C, 0x062D, 0x062E, 0x0633, 0x0634, 0x0635, 0x0636,
        0x0637, 0x0638, 0x0639, 0x063A, 0x0641, 0x0642, 0x0643, 0x0644, 0x0645, 0x0646, 0x0647, 0x064A,
    ];

    /** LAM + alef-variant => [isolated ligature, final ligature] */
    private const LAM_ALEF_LIGATURES = [
        0x0627 => [0xFEFB, 0xFEFC],
        0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA],
    ];

    private const LAM = 0x0644;

    /**
     * Shape every text node in an HTML string, leaving tags/attributes untouched.
     */
    public static function shapeHtml(string $html): string
    {
        return preg_replace_callback(
            '/(<[^>]*>)|([^<]+)/su',
            static fn (array $m) => $m[1] !== '' ? $m[1] : self::shape($m[2]),
            $html
        ) ?? $html;
    }

    /**
     * Reshape a plain-text string so connected Arabic letters render joined.
     * Non-Arabic characters (Latin, digits, punctuation, spaces) pass through
     * unchanged and act as connection breaks either side of them.
     */
    public static function shape(string $text): string
    {
        if ($text === '' || !preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        $chars = mb_str_split($text, 1, 'UTF-8');
        $codepoints = array_map(static fn (string $c) => mb_ord($c, 'UTF-8'), $chars);

        // Pre-pass: merge LAM + alef-variant into a single ligature unit.
        // Each unit is [codepoint(s) original, canConnectRight, canConnectLeft, forms]
        $units = [];
        $count = count($codepoints);
        for ($i = 0; $i < $count; $i++) {
            $cp = $codepoints[$i];

            if ($cp === self::LAM && $i + 1 < $count && isset(self::LAM_ALEF_LIGATURES[$codepoints[$i + 1]])) {
                [$iso, $final] = self::LAM_ALEF_LIGATURES[$codepoints[$i + 1]];
                $units[] = [
                    'canConnectRight' => true,  // behaves like LAM: accepts a connection from before
                    'canConnectLeft'  => false, // behaves like ALEF: never connects further
                    'isolated'        => $iso,
                    'final'           => $final,
                    'initial'         => null,
                    'medial'          => null,
                ];
                $i++; // consumed the alef too
                continue;
            }

            if (isset(self::FORMS[$cp])) {
                $isDual = in_array($cp, self::DUAL_JOINING, true);
                $isRight = in_array($cp, self::RIGHT_JOINING, true);
                [$iso, $final, $initial, $medial] = self::FORMS[$cp];
                $units[] = [
                    'canConnectRight' => $isDual || $isRight,
                    'canConnectLeft'  => $isDual,
                    'isolated'        => $iso,
                    'final'           => $final,
                    'initial'         => $initial,
                    'medial'          => $medial,
                ];
                continue;
            }

            // Not an Arabic letter we shape (space, digit, Latin, punctuation, …).
            $units[] = [
                'canConnectRight' => false,
                'canConnectLeft'  => false,
                'passthrough'     => $chars[$i],
            ];
        }

        $out = '';
        $unitCount = count($units);
        for ($i = 0; $i < $unitCount; $i++) {
            $unit = $units[$i];

            if (array_key_exists('passthrough', $unit)) {
                $out .= $unit['passthrough'];
                continue;
            }

            $prevConnects = $i > 0 && !array_key_exists('passthrough', $units[$i - 1])
                && $units[$i - 1]['canConnectLeft'] && $unit['canConnectRight'];
            $nextConnects = $i < $unitCount - 1 && !array_key_exists('passthrough', $units[$i + 1])
                && $unit['canConnectLeft'] && $units[$i + 1]['canConnectRight'];

            if ($prevConnects && $nextConnects) {
                $cp = $unit['medial'] ?? $unit['final'] ?? $unit['isolated'];
            } elseif ($prevConnects) {
                $cp = $unit['final'] ?? $unit['isolated'];
            } elseif ($nextConnects) {
                $cp = $unit['initial'] ?? $unit['isolated'];
            } else {
                $cp = $unit['isolated'];
            }

            $out .= mb_chr($cp, 'UTF-8');
        }

        return $out;
    }
}
