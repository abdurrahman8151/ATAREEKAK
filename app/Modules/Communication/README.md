# Communication module

Publishes messages and notifications. It is the module every other module talks to when a user must be told
something, and the only one that may read chat tables.

## Owned tables

| Model | Table | Status |
|---|---|---|
| `Conversation` | `conversations` | moved |
| `ConversationParticipant` | `conversation_participants` | moved |
| `Message` | `messages` | moved |
| `Notification` | `notifications` | moved |
| `UserNotification` | `user_notifications` | moved |
| `PushNotificationToken` | `push_notification_tokens` | moved |

All six live in `App\Modules\Communication\Models` with an explicit `protected $table`. Events published by this
module live in `App\Modules\Communication\Events` (`MessageSent`, `NotificationSent`).

## Contracts others may call

- `NotificationPort` - create an in-app notification for a user.
- `PushNotifierInterface` - push to a user's registered devices.
- `UnreadNotificationCounter` - how many unread, for a badge.
- `ChatRepositoryInterface` - the chat reads other modules need (participants, thread lookups).
- `MessageTypeInterface`, `MessageBroadcaster` - message semantics and live delivery.

## Rules

- Nobody outside this module reads `messages`, `conversations` or `notifications` directly. Chat reads other
  modules need go through `ChatRepositoryInterface`.
- A send is a write contract, never a model handed to a caller: a module that received a `Notification` row
  could edit it.
- The two `SerializesModels` events mean a deploy that moves them needs a drained queue (see
  [ADR 0009](../../../docs/adr/0009-events-move-with-their-publisher.md)).

## Known gap

`NotificationPort` ships a `test`-only path (`TestNotificationCommand`); it is a dev tool and exempt.
