# Telegram Events

Most updates go through normal core routing:

- messages become text events
- callback queries become action events
- media messages become inbound attachments

Some Telegram updates are platform lifecycle events and should not be modeled as normal text/action routes.

## onTelegramEvent

Use `onTelegramEvent()` for supported Telegram update types:

```php
$bot->onTelegramEvent('my_chat_member', static function (
    TelegramContext $telegram,
    Context $ctx,
    array $update
): void {
    $telegram->reply('Bot membership changed');
});
```

Supported update types:

- `my_chat_member`
- `chat_member`

These handlers run before normal core routing when the incoming update type matches.

## Handler Arguments

The container can inject:

- `Context`
- `TelegramContext`
- `InboundEventInterface`
- raw update as `array $update`
- services registered in the container

Named aliases are also provided internally for out-of-band handlers:

- `ctx`
- `context`
- `telegram`
- `event`
- `update`
- `rawUpdate`

## Output

Out-of-band handlers can still use queued effects:

```php
$ctx->reply('Membership changed');
$ctx->ack('Saved');
```

Effects are flushed after the handler returns.

## Errors

Exceptions are passed through the bot error handler registry. If an error handler queues replies or acknowledgements, those effects are also flushed.
