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
2. The collector waits for the window (the webhook request blocks for that long).
3. Only the part with the highest message id emits the aggregated update; earlier parts return
   `Result::noMatch('telegram_media_group_pending')`.
4. The aggregated event carries every attachment ordered by message id, with the chat and sender
   of the leading message.
5. The group is deleted from the store.

Parts that arrive after the window closed start a new group. Polling groups parts that arrive
in the same `getUpdates()` batch without waiting.

## Handling Albums

```php
$bot->onMedia('photo', static function (Context $ctx): void {
    $ctx->reply(sprintf('Received %d media items.', count($ctx->getAttachments())));
});
```

## Store Cleanup

`FileTelegramMediaGroupStore` removes stale groups on write with the configured probability
(default 5 percent, TTL 5 minutes). Use `InMemoryTelegramMediaGroupStore` in tests.
