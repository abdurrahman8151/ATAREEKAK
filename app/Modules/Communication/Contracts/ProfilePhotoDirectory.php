<?php

namespace App\Modules\Communication\Contracts;

/**
 * Stage 2 C2: the chat read path needs the stored `profile_photo` of the senders on a page.
 *
 * WHY THE PORT LIVES HERE AND NOT IN IDENTITY. `profiles` is Identity's table, but Communication may not
 * name Identity: phase 1.5 deliberately CUT the `Communication -> Identity` edge (and with it the
 * `Communication <-> Identity` cycle) when the `OtpMailInterface` binding moved to Identity's provider,
 * and `ModuleGraphTest` declares the remaining edges as shrink-only. Reaching Identity's own
 * `ProfileDirectory` from here would re-create that edge and that cycle. So the CONSUMER publishes the
 * narrow port it needs - one method, one column, no model - and Identity implements it; Communication
 * names only its own contract, and the graph grows by ONE PORT on the `Identity -> Communication` edge
 * that already exists (`NotificationPort`), not by a new edge.
 *
 * Returning scalars is the point: a `Profile` model handed back would re-import exactly what this
 * removes.
 */
interface ProfilePhotoDirectory
{
    /**
     * The STORED `profile_photo` path for each of the given users, keyed by user id.
     *
     * ONE query for the whole set - the chat page resolves every sender at once, so the call replaces the
     * `sender.profile` eager load it stood next to rather than becoming an N+1. A user with no profile row,
     * or with an empty photo, is present with a `null` value; a user id that does not exist is absent.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, string|null>
     */
    public function photoPathsFor(array $userIds): array;

    /**
     * Stage 4 C2: the WHOLE profile row per sender, keyed by user id - ONE query.
     *
     * Added because the chat send path used to attach the `User::profile` relation to the message it broadcasts,
     * and that kernel relation is gone with the model's move into Identity. Communication may not name Identity's
     * `ProfileDirectory` (that would re-create the `Communication <-> Identity` cycle stage 1.5 cut), so the read
     * grows HERE, on the port this module already publishes and Identity already implements.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, mixed>> the model's own serialization; an absent id means no profile row
     */
    public function profileRowsFor(array $userIds): array;
}
