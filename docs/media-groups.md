# Media Groups

Telegram sends album/media group items as separate updates. `chatflowphp/telegram` collects those updates before handing them to core.

## Collector

Default setup:

```php
new TelegramMediaGroupCollector(
    new FileTelegramMediaGroupStore($basePath . '/storage/telegram-media-groups')
);
```

The default wait window is `1200ms`.

## Behavior

When an incoming message has `media_group_id`:

1. The part is stored by `chat_id:media_group_id`.
2. The collector waits for the configured window.
3. Only the latest message id in the group emits the aggregated event.
4. Earlier updates return `Result::noMatch('telegram_media_group_pending')`.
5. The aggregated event contains all attachments ordered by Telegram message id.
6. The group is deleted from the store after dispatch.

## Handling Albums

```php
$bot->onMedia('photo', static function (Context $ctx): void {
    $count = count($ctx->getAttachments());
    $ctx->reply("Received {$count} media items.");
});
```

## Polling Optimization

Polling batches updates and groups album parts before normal handling. This reduces waiting when Telegram returns all group updates in one `getUpdates()` response.
