# Commands And Text Routes

Telegram text messages are normalized into core text events.

If you are new to the package, start with [Getting Started](getting-started.md) and come back here when you need the route catalog.

## Command Sugar

`Bot::command()` is Telegram-friendly sugar over core `onCommand()`:

```php
$bot->command('start', static function (Context $ctx): void {
    $ctx->reply('Welcome');
});
```

`onCommand('start')` matches:

- `/start`
- `/start argument text`

## Core Route API

`Bot` also exposes the full core route API:

```php
$bot->onTextPrefix('/search', $handler);
$bot->onTextRegex('/^order:\d+$/', $handler);
$bot->onCommand('help', $handler);
$bot->fallback($handler);
```

First registered route wins.

## Handler Dependencies

Handlers are called through the container. You can type-hint `Context` and services registered in the container:

```php
$bot->getContainer()->set(OrderService::class, new OrderService());

$bot->command('orders', static function (Context $ctx, OrderService $orders): void {
    $ctx->reply($orders->summaryFor((string) $ctx->getUserId()));
});
```

## Portable Code

Use `ChatFlow\Core\Context` for normal business handlers.

Only use `TelegramContext` when the handler needs Telegram-specific data or behavior.
