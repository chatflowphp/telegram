# Media And Files

## Outbound Media In Views

Portable outbound media uses core `MediaAttachment`:

```php
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;

$ctx->reply(
    View::text('Product photo')
        ->addMedia(new MediaAttachment('image', 'https://example.com/photo.jpg'))
);
```

Telegram maps:

- `image` to `sendPhoto`.
- `photo` to `sendPhoto`.
- `document` to `sendDocument`.
- `video` to `sendVideo`.
- `audio` to `sendAudio`.
- `animation` to `sendAnimation`.

Local file paths are sent through Telegram SDK `InputFile`.

## Inbound Attachments

Incoming Telegram media is normalized into `InboundAttachment` objects.

Supported inbound types:

- `photo`
- `document`
- `video`
- `audio`
- `voice`
- `animation`
- `sticker`
- `video_note`

Metadata preserves Telegram values where available:

- `file_id`
- `file_unique_id`
- `caption`
- `media_group_id`
- `message_id`
- dimensions
- duration
- mime type
- file size

## Media Routes

Use `onMedia()` for media handling outside active scenes:

```php
$bot->onMedia('photo', static function (Context $ctx): void {
    $path = $ctx->downloadAttachment(__DIR__ . '/storage/downloads');
    $ctx->reply($path === null ? 'Download failed' : 'Downloaded: ' . basename($path));
});

$bot->onMedia('any', $handler);
```

When a scene is active, media is handled by the scene flow instead of the out-of-band media handler.

## Downloading Telegram Files

`Context::downloadAttachment()` delegates to Telegram file API:

```php
$localPath = $ctx->downloadAttachment(__DIR__ . '/storage/downloads');
```

The first attachment file id is used.

## TelegramPublisher Media

Use `TelegramPublisher` when the application must immediately receive Telegram `message_id` values:

```php
use ChatFlow\Telegram\Media\TelegramMedia;
use ChatFlow\Telegram\Media\TelegramMediaSource;

$result = $publisher->sendMedia(
    chatId: $chatId,
    media: new TelegramMedia('photo', TelegramMediaSource::fileId($fileId), 'Caption')
);

$messageId = $result->getMessageId();
```

For media groups, local paths are not supported by `TelegramMediaSource::toMediaGroupApiValue()` because multipart `attach://` handling is not implemented in the publisher.
