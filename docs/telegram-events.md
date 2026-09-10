# Telegram Events

Most updates go through routing: messages become text events, callback queries become action
events, media messages become inbound attachments.

Some updates are platform lifecycle events. Use `onTelegramEvent()` for them:

```php
$bot->onTelegramEvent('my_chat_member', static function (TelegramContext $telegram, Context $ctx, array $update): void {
    $status = $update['my_chat_member']['new_chat_member']['status'] ?? 'unknown';
    $telegram->reply('Bot membership changed: ' . $status);
});
```

Supported update types: `my_chat_member`, `chat_member`.

## How They Run

The handler becomes a global custom route of the core `Application`: middleware runs, the
conversation of the chat is resumed, the handler may read and write `$ctx->session()`, and a
failure rolls everything back. The handler runs even when a scene is active.

## Handler Arguments

Resolved through the container by type or name:

- `Context` (`$ctx`, `$context`)
- `TelegramContext`
- `InboundEventInterface` (`$event`)
- the raw update as `array $update` (also `$rawUpdate`)
- any service registered in the container

## Output

`reply()`, `render()` and `ack()` queue effects that are delivered after the handler returns.

## Unsupported Updates

Updates without a chat (inline queries, chosen inline results, polls, shipping and pre-checkout
queries) are not handled by the runtime: `handle()` returns `Result::noMatch('unsupported_update')`
and no conversation is created. Use `Telegram\Bot\Api` directly for those.
