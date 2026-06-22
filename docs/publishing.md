# Publishing

Core `reply()` and `render()` are queued effects. They are right for normal conversational flows.

Use `TelegramPublisher` when the application must immediately call Telegram and persist returned `message_id` values, for example admin publishing to channels.

## Send Message

```php
$result = $publisher->sendMessage(
    chatId: '@channel',
    text: '<b>Post</b>',
    options: TelegramMessageOptions::html()
);

$messageId = $result->getMessageId();
```

## Send Media

```php
use ChatFlow\Telegram\Media\TelegramMedia;
use ChatFlow\Telegram\Media\TelegramMediaSource;

$result = $publisher->sendMedia(
    '@channel',
    new TelegramMedia(
        type: 'photo',
        source: TelegramMediaSource::url('https://example.com/photo.jpg'),
        caption: 'Caption',
        parseMode: 'HTML'
    )
);
```

Sources:

- `TelegramMediaSource::fileId($fileId)`
- `TelegramMediaSource::localPath($path)`
- `TelegramMediaSource::url($url)`

## Send Media Group

```php
$group = $publisher->sendMediaGroup('@channel', [
    new TelegramMedia('photo', TelegramMediaSource::fileId($fileId1)),
    new TelegramMedia('photo', TelegramMediaSource::fileId($fileId2)),
]);

$messageIds = $group->getMessageIds();
```

Telegram media groups must contain 2 to 10 items.

Current limitation: local paths in media groups are not supported by the publisher because multipart `attach://` handling is not implemented.

## Edit And Delete

```php
$publisher->editText($chatId, $messageId, 'Updated text');
$publisher->editCaption($chatId, $messageId, 'Updated caption');
$publisher->deleteMessage($chatId, $messageId);
```

## Result Objects

`TelegramDeliveryResult` contains:

- chat id
- message id
- endpoint
- raw response

`TelegramDeliveryGroupResult` contains:

- chat id
- message ids
- endpoint
- raw response
