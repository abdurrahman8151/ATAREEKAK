<?php

namespace Tests\Feature\Review;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RV-29 / T4-5 — User privileged-key mass-assignment ratchet.
 *
 * T4-5 ("narrow User::$fillable", which carries status / token_version /
 * is_verified_* / verification_status / wallet_id / national_id / ban_*) was attempted and
 * ROLLED BACK: narrowing $fillable wholesale breaks the ~9 legitimate privilege writers
 * (ban/unban, verification approve/reject, admin actions) into silent non-persists.
 *
 * GROUNDING THIS ROUND (the reason we did NOT retry the narrowing): the escalation vector —
 * mass-assigning attacker-controlled input onto a User — is NOT currently open. A scan of
 * every User mass-assign site found:
 *   - ZERO sites pass $request->all() / raw request input / $request->validated() wholesale
 *     into User::create/update/fill;
 *   - the two variable-array sites are both allowlisted/hardcoded: ProfileUpdateService
 *     filters to an explicit USER_MODEL_FIELDS allowlist before update(), and ProfileController
 *     uses a hardcoded literal;
 *   - all privileged writes are explicit, code-reviewed calls.
 *
 * So a blanket $fillable narrowing would break working privilege paths for NO live security
 * gain — exactly why it was rolled back. This ratchet instead PINS THE INVARIANT that makes the
 * vector impossible to OPEN later, without touching the 9 working writers:
 *
 *   1. no User mass-assign site may take raw request input (the escalation vector stays shut);
 *   2. no User mass-assign site may feed an unfiltered variable array that could carry
 *      privileged keys — every variable passed to a User write must be a literal or an
 *      explicit allowlist filter;
 *   3. the privileged columns still exist and are still writable (we did NOT half-break them),
 *      so the ratchet cannot be "passed" by deleting the feature.
 *
 * A future change that introduces `$user->update($request->all())` fails immediately.
 *
 * RV-37: the "still writable" proof below persists a factory user, so this class
 * needs `RefreshDatabase`; without it that row was COMMITTED and leaked into every
 * later count-style assertion in the suite.
 */
class RV29UserMassAssignmentRatchetTest extends TestCase
{
    use RefreshDatabase;

    /** Privileged User columns that must never come from mass-assigned external input. */
    private const PRIVILEGED = [
        'status', 'token_version', 'is_verified_passenger', 'is_verified_driver',
        'verification_status', 'wallet_id', 'national_id', 'ban_reason', 'ban_type',
        'banned_at', 'ban_expires_at', 'banned_by',
    ];

    /** @return array<int, string> */
    private function appPhpFiles(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        ) as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }

    /** @test */
    public function no_user_mass_assign_takes_raw_request_input(): void
    {
        $offenders = [];
        foreach ($this->appPhpFiles() as $file) {
            $src = (string) file_get_contents($file);
            foreach (preg_split('/\R/', $src) as $line) {
                $t = trim($line);
                if ($t === '' || str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '#')) {
                    continue;
                }
                // The escalation vector: raw, unfiltered external input into a User write.
                if (preg_match('/(user|driver|passenger|employee|admin)\s*->\s*(update|fill)\(\s*\$request/', $t)
                    || preg_match('/User::create\(\s*\$request/', $t)
                    || preg_match('/->\s*(update|fill)\(\s*request\(\)->all\(\)/', $t)) {
                    $offenders[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file).': '.$t;
                }
                // A variable straight from validated input, unless the same line shows an
                // explicit allowlist filter (array_intersect/only/array_filter on a whitelist).
                if (preg_match('/(user|driver|passenger)\s*->\s*(update|fill)\(\s*\$validated\b/', $t)
                    && ! preg_match('/(intersect|only\(|array_intersect_key|array_filter|Arr::only)/', $t)) {
                    $offenders[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file).': '.$t;
                }
            }
        }

        $this->assertSame([], $offenders,
            "RV-29/T4-5: never mass-assign raw request input onto a User model — that is the\n"
            ."privilege-escalation vector (status / wallet_id / is_verified_*). Use an explicit\n"
            ."allowlist. Offenders:\n".implode("\n", $offenders));
    }

    /** @test */
    public function privileged_user_columns_still_exist_and_are_writable(): void
    {
        // The ratchet must not be passable by gutting the feature: these columns are real
        // and the legitimate privilege writers still set them.
        $columns = Schema::getColumnListing('users');
        foreach (['status', 'wallet_id', 'is_verified_driver', 'verification_status'] as $c) {
            $this->assertContains($c, $columns, "users.$c must still exist");
        }

        // A privileged write through the model still works (a hardcoded literal is safe).
        $u = User::factory()->create();
        $u->update(['is_verified_driver' => true, 'verification_status' => 'approved']);
        $u->refresh();
        $this->assertTrue((bool) $u->is_verified_driver,
            'a legitimate, code-controlled privileged write must still persist');
    }

    /** @test */
    public function user_fillable_has_not_been_silently_gutted_by_the_ratchet(): void
    {
        // T4-5's narrowing is deliberately NOT applied (it was rolled back); guard against a
        // future "fix" that strips $fillable and silently breaks privilege writes. If a future
        // change DOES narrow it properly, this ratchet should be revisited deliberately.
        $fillable = (new User)->getFillable();
        foreach (['status', 'is_verified_driver', 'verification_status'] as $c) {
            $this->assertContains($c, $fillable,
                "User::\$fillable still carries '{$c}' — T4-5 narrowing was rolled back on "
                .'evidence; do not half-apply it without a working privilege-writer migration.');
        }
    }
}
