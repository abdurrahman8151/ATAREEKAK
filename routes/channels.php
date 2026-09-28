<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

// Default Laravel channel
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private chat conversation channel
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);
    return $conversation && $conversation->isParticipant($user);
});

// Private user channel (notifications)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Rides broadcast channel.
//
// Original-audit T2-7: this was a PUBLIC Pusher channel whose callback
// returned an unconditional `true`. A public channel never reaches this
// callback at all — the browser subscribes with the (also-defaulted, T2-8)
// app key directly and bypasses /broadcasting/auth entirely — so `fn () => true`
// was dead code, and the ride-creation / cancellation stream was open to any
// unauthenticated holder of the key. The same ride data is served over REST
// only behind `jwt` (every api/rides/* route is AUTH), so this was a genuine
// authorization gap, not a design choice.
//
// It is now a PRIVATE channel (see RideCreated / RideCancelled broadcastOn):
// the client must hit /broadcasting/auth, which BroadcastServiceProvider wraps
// in the `jwt` middleware, before it can subscribe. The single global `rides`
// channel name carries no ride id, so a per-ride relationship check is not
// possible here; the coherent policy — and one the audit explicitly lists as
// acceptable ("or authenticated user") — is any authenticated user. Guests are
// refused. The `null` guard is defensive: today `jwt` already guarantees a
// user, but the authorization callback must not silently admit a null identity
// if the route guard is ever changed.
//
// The broadcast payloads carry ride attributes + driver name only (no
// passenger identity), so no payload redaction is required at this tier.
Broadcast::channel('rides', function ($user) {
    return $user !== null;
});
