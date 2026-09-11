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

Supported update types: `my_chat_member` and `chat_member`. Registering any other type throws:
they are not dispatched here, and a handler that never runs is worse than an error.

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

## Updates Without A Chat

Inline queries, chosen inline results, polls, shipping and pre-checkout queries carry no chat, so
they cannot become a conversation event. Handle them with `onRawUpdate()`:

```php
$bot->onRawUpdate('pre_checkout_query', static function (Api $api, array $update): void {
    $api->answerPreCheckoutQuery(['pre_checkout_query_id' => $update['pre_checkout_query']['id'], 'ok' => true]);
});
```

Without a handler `handle()` returns `Result::noMatch('unsupported_update')` and no conversation
is created. See [Payments And Updates Without A Chat](payments.md).
