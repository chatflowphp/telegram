# Media And Files

## Outbound Media In Views

```php
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;

$ctx->reply(View::text('Product photo')->addMedia(new MediaAttachment('image', 'https://example.com/photo.jpg')));
```

Telegram maps `image` and `photo` to `sendPhoto`, `document` to `sendDocument`, `video` to
`sendVideo`, `audio` to `sendAudio`, `animation` to `sendAnimation`. Local file paths are sent
through the SDK `InputFile`. `render()` of a media view edits the current media message when it
is an editable media type.

## Inbound Attachments

Incoming Telegram media is normalized into `InboundAttachment` objects for `photo`, `document`,
`video`, `audio`, `voice`, `animation`, `sticker` and `video_note`. Metadata keeps `file_id`,
`file_unique_id`, `caption`, `media_group_id`, `message_id`, dimensions, duration, mime type and
file size where Telegram provides them.

## Media Routes

```php
$bot->onMedia('photo', static function (Context $ctx): void {
    $path = $ctx->downloadAttachment(__DIR__ . '/storage/downloads');
    $ctx->reply($path === null ? 'Download failed' : 'Downloaded: ' . basename($path));
});

$bot->onMedia('any', $handler);
```

Media handlers run through middleware with session access, but only when no scene is active.
Inside a scene the attachment goes to the scene: an `onMedia()` shortcut of the pending
interaction, otherwise `handle()`.

## Downloading Files

`$ctx->downloadAttachment($dir)` downloads the first attachment through the Bot API and returns
the local path, or `null`.

## Publisher Media

```php
$result = $publisher->sendMedia($chatId, new TelegramMedia('photo', TelegramMediaSource::fileId($fileId), 'Caption'));
$messageId = $result->getMessageId();
```

Media groups through the publisher accept URLs and file ids; local paths are not supported
because multipart `attach://` handling is not implemented.
