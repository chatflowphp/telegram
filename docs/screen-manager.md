# Screen Manager

`TelegramScreenManager` is a Telegram-specific helper for best-effort cleanup of message groups.

It is useful in admin screens where the application sends several messages through `TelegramPublisher` and wants to delete the previous group on the next render.

## Basic Usage

```php
use ChatFlow\Telegram\UI\TelegramScreenManager;

$bot->command('admin', static function (
    Context $ctx,
    TelegramScreenManager $screens,
    TelegramPublisher $publisher
): void {
    $screens->beginGroup('admin-home');

    $result = $publisher->sendMessage($ctx->getConversationId(), 'Admin screen');
    $screens->track($result);
});
```

Calling `beginGroup('admin-home')` clears the previous tracked messages for the same conversation and group name by calling `deleteMessage()` best-effort.

## Important Boundary

`TelegramScreenManager` tracks delivery results from `TelegramPublisher`.

It does not automatically track all core `reply()` or `render()` effects.

For ordinary bot screens, prefer `ctx->render()` and let the Telegram adapter do smart edit/delete/send behavior.

## Methods

```php
$screens->beginGroup('name', clearPrevious: true);
$screens->clearGroup('name');
$screens->track($result);
```

Cleanup failures are swallowed because screen cleanup must not break the user-facing flow.
