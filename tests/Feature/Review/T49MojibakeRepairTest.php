<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Services\Admin\AdminReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * T4-9 (BACKLOG row 108): the Windows-1252 mojibake was repaired in user-visible Arabic literals,
 * and in the stored `users.address` values that the validator and the city report both depend on.
 *
 * The repair is only correct if THREE things agree: the stored rows, the validator, and the report.
 * Each is pinned here, because a fix to one that leaves the others on the garbled form would
 * silently drop every affected user from the city report and reject their profile updates.
 */
class T49MojibakeRepairTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The migration's own map, read from the file, so this test cannot drift from what ships. The
     * garbled keys are NEVER hand-typed here: a hand-typed mojibake literal is exactly the kind of
     * byte-level error this repair exists to fix, and it would make the tests check the wrong value.
     */
    private static function migrationMap(): array
    {
        $migration = require database_path('migrations/2026_10_12_120000_repair_mojibake_user_addresses.php');

        // MAP is a class CONSTANT, not a property, so it is read with getConstant().
        return (new \ReflectionClass($migration))->getConstant('MAP') ?: [];
    }

    public function test_the_migration_map_holds_exactly_the_fourteen_cities(): void
    {
        $map = self::migrationMap();

        $this->assertCount(14, $map, 'one pair per Syrian governorate');
        $this->assertSame(
            14,
            count(array_unique(array_values($map))),
            'every garbled key must map to a distinct correct value - a collision would merge two cities'
        );
    }

    public function test_the_idlib_value_is_the_hamza_spelling_the_owner_chose(): void
    {
        $this->assertContains(
            "\u{0625}\u{062F}\u{0644}\u{0628}",
            array_values(self::migrationMap()),
            'Idlib must be the hamza form (owner decision 2026-10-12), not the bare alef the bug preserved'
        );
    }

    public function test_up_rewrites_a_garbled_stored_address_to_the_correct_arabic(): void
    {
        $garbled = array_key_first(self::migrationMap());
        $correct = self::migrationMap()[$garbled];
        $user = User::factory()->create(['address' => $garbled]);

        $migration = require database_path('migrations/2026_10_12_120000_repair_mojibake_user_addresses.php');
        $migration->up();

        $this->assertSame($correct, $user->fresh()->address, 'the stored row must be repaired');
    }

    public function test_up_then_down_is_an_exact_round_trip(): void
    {
        $garbled = array_key_first(self::migrationMap());
        $user = User::factory()->create(['address' => $garbled]);
        $migration = require database_path('migrations/2026_10_12_120000_repair_mojibake_user_addresses.php');

        $migration->up();
        $migration->down();

        $this->assertSame($garbled, $user->fresh()->address, 'down() must restore the exact original bytes');
    }

    public function test_a_row_that_is_not_a_garbled_city_is_left_alone(): void
    {
        $unrelated = 'Some free-text address';
        $user = User::factory()->create(['address' => $unrelated]);
        $migration = require database_path('migrations/2026_10_12_120000_repair_mojibake_user_addresses.php');

        $migration->up();

        $this->assertSame($unrelated, $user->fresh()->address, 'only the exact 14 garbled values may change');
    }

    /**
     * The `in:` rule as it actually SHIPS in ProfileController, read from the source file.
     *
     * Building the rule from the migration map inside the test would be a fake test: it would pass
     * even if the validator still held the garbled list. Reverting ProfileController to its garbled
     * `in:` list MUST fail here - that is what this test exists to prevent.
     *
     * @return array<int, string>
     */
    private static function shippedAddressRule(): array
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/app/Http/Controllers/API/ProfileController.php'
        );
        if (! preg_match("/'address' => 'nullable\|in:([^']+)'/u", $source, $m)) {
            return [];
        }

        return explode(',', $m[1]);
    }

    public function test_the_shipped_validator_rule_is_exactly_the_fourteen_repaired_cities(): void
    {
        $shipped = self::shippedAddressRule();

        $this->assertNotEmpty($shipped, 'the address `in:` rule must still exist in ProfileController');

        $expected = array_values(self::migrationMap());
        sort($expected);
        $sorted = $shipped;
        sort($sorted);

        $this->assertSame(
            $expected,
            $sorted,
            'ProfileController must validate against the REPAIRED cities, not the garbled ones'
        );
        $this->assertContains(
            "\u{0625}\u{062F}\u{0644}\u{0628}",
            $shipped,
            'Idlib in the shipped rule must be the hamza form the owner chose'
        );
    }

    public function test_the_shipped_validator_accepts_the_correct_arabic_and_refuses_the_garbled_form(): void
    {
        $rules = ['address' => 'nullable|in:'.implode(',', self::shippedAddressRule())];
        $correct = self::migrationMap()[array_key_first(self::migrationMap())];
        $garbled = array_key_first(self::migrationMap());

        $this->assertFalse(
            Validator::make(['address' => $correct], $rules)->fails(),
            'the repaired city must pass the shipped validation rule'
        );
        $this->assertTrue(
            Validator::make(['address' => $garbled], $rules)->fails(),
            'the garbled form must NOT pass - it is no longer a valid value'
        );
    }

    public function test_the_city_report_maps_a_repaired_city_to_its_english_name(): void
    {
        $garbled = array_key_first(self::migrationMap());
        $correct = self::migrationMap()[$garbled];
        User::factory()->create(['address' => $garbled]);
        $migration = require database_path('migrations/2026_10_12_120000_repair_mojibake_user_addresses.php');
        $migration->up();

        $rows = app(AdminReportService::class)->getCityDistribution();

        $this->assertContains(
            'Damascus',
            array_column($rows, 'city_en'),
            'the repaired row must reach the report and be mapped to its English name'
        );
        // `city` is the raw stored address and `city_en` falls back to it when unmapped
        // (`AdminReportService:196`), so an unmapped row shows the STORED text, never a literal
        // "Unknown". Asserting on the absence of "Unknown" would be vacuous; assert the value.
        $this->assertContains(
            $correct,
            array_column($rows, 'city'),
            'the report must expose the repaired Arabic, not the garbled value'
        );
        $this->assertNotContains(
            $garbled,
            array_column($rows, 'city'),
            'no garbled value may survive into the report'
        );
    }
}
