# Communication

Chat, push notifications and the notification fan-out: the module that delivers something to a user.

## What it owns

- `Services/NotificationService` - the fan-out for in-app, push and mail notifications, behind
  `Contracts/NotificationPort`.
- `Services/ChatMessageHandler` and `Repositories/ChatRepository` - conversations, messages, read state.
- `Services/PushNotificationService`, `Services/FcmSenderService`, `Services/PushTokenManager` - device
  tokens and FCM delivery.
- `Contracts/MessageTypeInterface` - the message-type vocabulary.
- The kernel's `app/Mail` transport belongs to this context in the boundary map: mail delivery is a
  Communication concern, which is why `App\Mail\OtpMailer` is described here even though the file stays
  in the kernel (ADR 0004).

## Public Contracts (5)

| Port | Implemented by | Consumers today |
|---|---|---|
| `NotificationPort` | `NotificationService` | Console (7 files), Identity (2), Money (1), Ride (12) |
| `PushNotifierInterface` | `PushNotificationService` | kernel jobs (`SendPushNotification`, `SendPushNotificationJob`) |
| `ChatMessageHandlerInterface` | `ChatMessageHandler` | Console (`StaffChatController`) |
| `ChatRepositoryInterface` | `ChatRepository` | Communication's own `ContactController`, Console's `StaffChatController` |
| `MessageTypeInterface` | - (vocabulary) | - |

## Who may call it

Any module, through the ports above. It is the most-depended-on module in the graph, by design: almost
everything the system does ends in a notification, and routing that through one port keeps the queue,
the FCM client and the chat schema in one place.

Its own dependencies: **Console** (`SupportAgentRoutingInterface`, `EmployeeManagementInterface`, used by
`ContactController` to park a passenger with the least-loaded support agent). That is the
`Communication <-> Console` cycle, declared in `ModuleGraphTest` with its reason.

## What it may not do

- Import another module's implementation (rule A). It previously imported `Identity\Contracts\OtpMailInterface`
  in its provider; stage 1.5 item 4 moved that binding to the kernel catalogue and the
  `Communication -> Identity` edge - and its cycle - is gone.
- Let a chat or notification rule leak into a ride or wallet service: consumers get a port, not the
  service.

## Tests

`tests/Feature/Notifications`, `tests/Feature/Chat`, `tests/Unit/Services` (notification/chat units), plus
the boundary ratchets in `tests/Feature/AppFuture`.

## Owned tables

Every table has one owner ([ADR 0006](../../../docs/adr/0006-strict-data-ownership-schema-frozen.md));
the schema is frozen and each model declares its table explicitly.

| Model | Table | Class location |
|---|---|---|
| `Conversation` | `conversations` | this module: `App\Modules\Communication\Models` |
| `ConversationParticipant` | `conversation_participants` | this module: `App\Modules\Communication\Models` |
| `Message` | `messages` | this module: `App\Modules\Communication\Models` |
| `Notification` | `notifications` | this module: `App\Modules\Communication\Models` |
| `UserNotification` | `user_notifications` | this module: `App\Modules\Communication\Models` |
| `PushNotificationToken` | `push_notification_tokens` | this module: `App\Modules\Communication\Models` |

## Deploy notes: drain the queue before deploying

`MessageSent` and `NotificationSent` both use `SerializesModels`, so a payload queued before they moved
cannot be deserialised after it. Deploy with the queue empty, or drain it first. See
[ADR 0009](../../../docs/adr/0009-events-move-with-their-publisher.md) and
[ARCHITECTURE.md § 12](../../../docs/ARCHITECTURE.md).
