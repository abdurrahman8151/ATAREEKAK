<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T4-9 (R2 sec 119 follow-up, owner decision 2026-10-11: repair in place).
 *
 * `users.address` was written through the Windows-1252 mojibake bug: the 14 Syrian city names were
 * stored as UTF-8 that had been read as Windows-1252 and written back, so the registration validator
 * and the city report both compared against the garbled form. This rewrites ONLY those exact stored
 * values to the correct Arabic. The map is 1:1, so `down()` is exact.
 *
 * Match is BINARY so a collation cannot fold two distinct garbled strings together.
 * The ASCII-only literal escapes are deliberate: this file must survive any editor re-encoding.
 */
return new class extends Migration
{
    /** @var array<string, string> garbled => correct */
    private const MAP = [
        "\u{D8}\u{AF}\u{D9}\u{2026}\u{D8}\u{B4}\u{D9}\u{201A}" => "\u{62F}\u{645}\u{634}\u{642}",
        "\u{D8}\u{AD}\u{D9}\u{201E}\u{D8}\u{A8}" => "\u{62D}\u{644}\u{628}",
        "\u{D8}\u{AD}\u{D9}\u{2026}\u{D8}\u{B5}" => "\u{62D}\u{645}\u{635}",
        "\u{D8}\u{A7}\u{D9}\u{201E}\u{D9}\u{201E}\u{D8}\u{A7}\u{D8}\u{B0}\u{D9}\u{201A}\u{D9}\u{160}\u{D8}\u{A9}" => "\u{627}\u{644}\u{644}\u{627}\u{630}\u{642}\u{64A}\u{629}",
        "\u{D8}\u{AF}\u{D8}\u{B1}\u{D8}\u{B9}\u{D8}\u{A7}" => "\u{62F}\u{631}\u{639}\u{627}",
        "\u{D8}\u{AD}\u{D9}\u{2026}\u{D8}\u{A7}\u{D8}\u{A9}" => "\u{62D}\u{645}\u{627}\u{629}",
        "\u{D8}\u{B1}\u{D9}\u{160}\u{D9}\u{81} \u{D8}\u{AF}\u{D9}\u{2026}\u{D8}\u{B4}\u{D9}\u{201A}" => "\u{631}\u{64A}\u{641} \u{62F}\u{645}\u{634}\u{642}",
        "\u{D8}\u{B7}\u{D8}\u{B1}\u{D8}\u{B7}\u{D9}\u{2C6}\u{D8}\u{B3}" => "\u{637}\u{631}\u{637}\u{648}\u{633}",
        "\u{D8}\u{A7}\u{D9}\u{201E}\u{D8}\u{B3}\u{D9}\u{2C6}\u{D9}\u{160}\u{D8}\u{AF}\u{D8}\u{A7}\u{D8}\u{A1}" => "\u{627}\u{644}\u{633}\u{648}\u{64A}\u{62F}\u{627}\u{621}",
        "\u{D8}\u{A7}\u{D9}\u{201E}\u{D9}\u{201A}\u{D9}\u{2020}\u{D9}\u{160}\u{D8}\u{B7}\u{D8}\u{B1}\u{D8}\u{A9}" => "\u{627}\u{644}\u{642}\u{646}\u{64A}\u{637}\u{631}\u{629}",
        "\u{D8}\u{A7}\u{D8}\u{AF}\u{D9}\u{201E}\u{D8}\u{A8}" => "\u{625}\u{62F}\u{644}\u{628}",
        "\u{D8}\u{A7}\u{D9}\u{201E}\u{D8}\u{AD}\u{D8}\u{B3}\u{D9}\u{192}\u{D8}\u{A9}" => "\u{627}\u{644}\u{62D}\u{633}\u{643}\u{629}",
        "\u{D8}\u{A7}\u{D9}\u{201E}\u{D8}\u{B1}\u{D9}\u{201A}\u{D8}\u{A9}" => "\u{627}\u{644}\u{631}\u{642}\u{629}",
        "\u{D8}\u{AF}\u{D9}\u{160}\u{D8}\u{B1} \u{D8}\u{A7}\u{D9}\u{201E}\u{D8}\u{B2}\u{D9}\u{2C6}\u{D8}\u{B1}" => "\u{62F}\u{64A}\u{631} \u{627}\u{644}\u{632}\u{648}\u{631}",
    ];

    public function up(): void
    {
        foreach (self::MAP as $garbled => $correct) {
            DB::table('users')
                ->whereRaw('BINARY address = ?', [$garbled])
                ->update(['address' => $correct]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $garbled => $correct) {
            DB::table('users')
                ->whereRaw('BINARY address = ?', [$correct])
                ->update(['address' => $garbled]);
        }
    }
};
