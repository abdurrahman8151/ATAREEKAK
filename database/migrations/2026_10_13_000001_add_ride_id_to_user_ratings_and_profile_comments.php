<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the two `ride_id` columns production already carries.
 *
 * Production's `migrations` table holds `2026_08_21_000001_add_ride_id_to_profile_comments_table`
 * and `2026_08_21_000002_add_ride_id_to_user_ratings_table`, but neither migration file exists in
 * this repository. Everything rebuilt from the committed migrations therefore lacked the columns
 * that the profile comment/rating code reads and writes
 * (`ProfileInteractionService::addComment/rateUser/assertEligible`), and both endpoints answered
 * 500 "Unknown column 'ride_id' in 'where clause'" (audit 229.4). This migration is the stand-in
 * for both of them.
 *
 * The shape is production's `SHOW CREATE TABLE` output, verbatim:
 *
 *   user_ratings
 *     "ride_id" bigint unsigned DEFAULT NULL, after "rated_user_id"
 *     KEY "user_ratings_ride_id_foreign" ("ride_id")
 *     CONSTRAINT "user_ratings_ride_id_foreign" FOREIGN KEY ("ride_id")
 *         REFERENCES "rides" ("id") ON DELETE SET NULL
 *     UNIQUE KEY "user_ratings_rater_id_rated_user_id_ride_id_unique"
 *         ("rater_id","rated_user_id","ride_id")
 *   profile_comments
 *     "ride_id" bigint unsigned DEFAULT NULL, after "user_id"
 *     KEY "profile_comments_ride_id_foreign" ("ride_id")
 *     CONSTRAINT "profile_comments_ride_id_foreign" FOREIGN KEY ("ride_id")
 *         REFERENCES "rides" ("id") ON DELETE SET NULL
 *
 * Every change is guarded (`Schema::hasColumn`, `Schema::getIndexes`), so on production, where the
 * columns, keys and constraints already exist, this migration is a pure no-op, and on a schema
 * that has only part of the shape it applies only the missing part.
 *
 * nullOnDelete mirrors production: a rating or comment survives the deletion of its ride, but the
 * link is cleared. The per-ride unique key replaces the old per-pair one so that one rater can rate
 * the same person once per ride (RV-30), which is what the service's 409 branch assumes.
 *
 * down(): never roll this back on production - production owned these objects before this file
 * existed, and this repository's `down()` can only recreate the pre-restore shape of a rebuilt
 * schema. The guards make it safe to run against a schema that is already partly reverted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addRideId('profile_comments', 'user_id');
        $this->addRideId('user_ratings', 'rated_user_id');

        $this->replacePairUniqueWithPerRideUnique();
    }

    public function down(): void
    {
        $this->restorePairUnique();

        $this->dropRideId('user_ratings');
        $this->dropRideId('profile_comments');
    }

    /**
     * Add the nullable ride link + its index and ON DELETE SET NULL foreign key, unless present.
     */
    private function addRideId(string $table, string $after): void
    {
        if (Schema::hasColumn($table, 'ride_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($after) {
            $blueprint->foreignId('ride_id')->nullable()->after($after)
                ->constrained('rides')->nullOnDelete();
        });
    }

    /**
     * Drop the ride link (its FK first, then the column, which takes the index and any unique key
     * that contained the column with it), unless it is already gone.
     */
    private function dropRideId(string $table): void
    {
        if (! Schema::hasColumn($table, 'ride_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropForeign(['ride_id']);
        });

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('ride_id');
        });
    }

    /**
     * Production's unique key is (rater_id, rated_user_id, ride_id). Match it by column set, not by
     * name, so a schema that already has the key under any name is left alone.
     */
    private function replacePairUniqueWithPerRideUnique(): void
    {
        $perRide = ['rater_id', 'rated_user_id', 'ride_id'];
        $indexes = Schema::getIndexes('user_ratings');

        foreach ($indexes as $index) {
            if ($this->isUniqueOver($index, $perRide)) {
                return;
            }
        }

        foreach ($indexes as $index) {
            if ($this->isUniqueOver($index, ['rater_id', 'rated_user_id'])) {
                Schema::table('user_ratings', function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropUnique($index['name']);
                });
            }
        }

        Schema::table('user_ratings', function (Blueprint $blueprint) use ($perRide) {
            $blueprint->unique($perRide, 'user_ratings_rater_id_rated_user_id_ride_id_unique');
        });
    }

    /**
     * Put the pre-restore per-pair unique key back, unless one is already there.
     */
    private function restorePairUnique(): void
    {
        if (! Schema::hasColumn('user_ratings', 'ride_id')) {
            return;
        }

        $pair = ['rater_id', 'rated_user_id'];
        $indexes = Schema::getIndexes('user_ratings');

        foreach ($indexes as $index) {
            if ($this->isUniqueOver($index, $pair)) {
                return;
            }
        }

        foreach ($indexes as $index) {
            if ($this->isUniqueOver($index, ['rater_id', 'rated_user_id', 'ride_id'])) {
                Schema::table('user_ratings', function (Blueprint $blueprint) use ($index) {
                    $blueprint->dropUnique($index['name']);
                });
            }
        }

        Schema::table('user_ratings', function (Blueprint $blueprint) use ($pair) {
            $blueprint->unique($pair, 'user_ratings_rater_id_rated_user_id_unique');
        });
    }

    /**
     * @param  array<string, mixed>  $index
     * @param  list<string>  $columns
     */
    private function isUniqueOver(array $index, array $columns): bool
    {
        return ($index['unique'] ?? false) === true
            && array_values($index['columns'] ?? []) === $columns;
    }
};
