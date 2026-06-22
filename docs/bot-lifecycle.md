# Bot Lifecycle

`ChatFlow\Telegram\Bot` is the Telegram-facing runtime facade.

It owns:

- Telegram SDK `Api`.
- Core `Application`.
- Core `Router`.
- Core container.
- Scene registry.
- Validation registry.
- Optional state manager.
- Telegram platform adapter.
- Telegram publisher.
- Callback payload encoder.
- Media group collector.

## Construction

```php
$bot = new Bot(
    token: $_ENV['TELEGRAM_BOT_TOKEN'],
    basePath: __DIR__,
);
```

Optional constructor arguments allow injecting logger, container, webhook secret, config, SDK `Api`, error handler, router, scene registry, validation registry, application, runtime observer, callback encoder, media group collector and publisher.

## Handling Updates

`handle()` accepts a Telegram SDK `Update` or raw update array:

```php
$result = $bot->handle($rawUpdate);
```

Processing order:

1. Detect out-of-band Telegram update handlers such as `my_chat_member`.
2. Collect Telegram media groups when needed.
3. Normalize the update into a core inbound event.
4. Route media handlers when a media handler is registered and no scene is active.
5. Delegate regular text/action handling to core `Application`.
6. Flush queued outbound effects through `TelegramPlatformAdapter`.

## Webhook

```php
$result = $bot->runWebhook();
```

If a webhook secret was configured, incoming webhook execution validates Telegram's secret token header before handling the update.

## Polling

```php
$bot->startPolling(timeout: 30);
```

Polling deletes the webhook first, then calls `getUpdates()` in a loop and handles updates by offset.

Allowed updates can be passed explicitly:

```php
$bot->startPolling(30, ['message', 'callback_query', 'my_chat_member']);
```

## Runtime Access

```php
$bot->getApi();
$bot->getApplication();
$bot->getContainer();
$bot->getValidationRegistry();
$bot->getStateManager();
$bot->getPublisher();
```

Use `getApi()` only as an escape hatch for Telegram endpoints that ChatFlow does not model.

## Error Policy

Use typed exception handlers for expected domain errors:

```php
$bot->onException(ProductNotFoundException::class, static function (
    Throwable $e,
    ?Context $ctx
): void {
    $ctx?->ack('Product is not available.', true);
});
```

Use a fallback error handler for unexpected failures:

```php
$bot->setErrorHandler(static function (Throwable $e, Context $ctx): void {
    $ctx->reply('Unexpected error. Type /start to retry.');
});
```
