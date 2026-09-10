# Screen Manager

`TelegramScreenManager` is a Telegram-specific helper for best-effort cleanup of message groups
published with `TelegramPublisher`, typically admin screens made of several messages.

```php
use ChatFlow\Telegram\UI\TelegramScreenManager;

$bot->command('admin', static function (Context $ctx, TelegramScreenManager $screens, TelegramPublisher $publisher): void {
    $screens->beginGroup('admin-home');
    $screens->track($publisher->sendMessage($ctx->getConversationId(), 'Admin screen'));
    $screens->track($publisher->sendMediaGroup($ctx->getConversationId(), $charts));
});
```

`beginGroup('admin-home')` deletes the messages tracked under that name for the current
conversation, then makes it the active group. `track()` records the message ids of a delivery
result.

## Persistence

Tracked ids are stored in the conversation session (extension `telegram.screens`), so cleanup
works across webhook requests and survives restarts with persistent storage. Without a
conversation (manager used outside a handled update) ids are kept in memory.

## Methods

```php
$screens->beginGroup('name', clearPrevious: true);
$screens->clearGroup('name');
$screens->track($result, 'name');
$screens->tracked('name');
```

Cleanup failures are swallowed: deleting old messages must never break the user-facing flow.

For ordinary bot screens prefer `$ctx->render()`; the adapter edits the current message or
deletes and resends it on its own.
