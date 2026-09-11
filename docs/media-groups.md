# Media Groups

Telegram sends album items as separate updates. The adapter collects them before handing one
event to the core.

## Collector

```php
new TelegramMediaGroupCollector(
    new FileTelegramMediaGroupStore($basePath . '/storage/telegram-media-groups'),
    waitWindowMs: 1200,
);
```

## Behaviour

When a message or an edited message has `media_group_id`:

1. The part is stored under `chat_id:media_group_id`.
2. The first request to see the album claims it and waits for the window; every other part
   returns `Result::noMatch('telegram_media_group_pending')` immediately, so at most one webhook
   worker per album is blocked.
3. The leader emits one update carrying every part collected so far, ordered by message id, with
   the chat and sender of the first message.
4. The group is deleted from the store.

Parts that arrive after the window closed start a new group. Polling groups parts that arrive
in the same `getUpdates()` batch without waiting. Custom stores implement `claim()` atomically
(the file store creates a marker with the exclusive `x` mode).

## Handling Albums

```php
$bot->onMedia('photo', static function (Context $ctx): void {
    $ctx->reply(sprintf('Received %d media items.', count($ctx->getAttachments())));
});
```

## Store Cleanup

`FileTelegramMediaGroupStore` removes stale groups on write with the configured probability
(default 5 percent, TTL 5 minutes). Use `InMemoryTelegramMediaGroupStore` in tests.
