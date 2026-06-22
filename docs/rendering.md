# Rendering

Telegram output is described with core `View` objects and delivered by the Telegram adapter.

## Reply

`reply()` sends a new Telegram message:

```php
$ctx->reply('New message');
$ctx->reply(View::text('Message with buttons')->addActionRow($action));
```

## Render

`render()` is for replacing the current screen:

```php
$ctx->render(View::text('Updated screen'));
```

Telegram delivery strategy:

1. If the event is a callback action and the current message is editable text, try `editMessageText`.
2. If the view contains editable media, try `editMessageMedia`.
3. If editing is not possible, try deleting the current message.
4. Send a new message or media message.

Render fallback failures are logged. The flow fails only if final delivery fails.

## Ack

`ack()` gives lightweight action feedback:

```php
$ctx->ack();
$ctx->ack('Saved');
$ctx->ack('Cannot do this', true);
```

For callback queries, it uses Telegram `answerCallbackQuery()`.

## Telegram Message Options

Attach Telegram Bot API message options through `TelegramView`:

```php
use ChatFlow\Telegram\TelegramMessageOptions;
use ChatFlow\Telegram\TelegramView;

$ctx->reply(
    TelegramView::options(
        '<b>Hello</b>',
        TelegramMessageOptions::html()
            ->withDisableWebPagePreview(true)
            ->withProtectContent(true)
    )
);
```

Supported first-class options:

- `parse_mode`
- `disable_web_page_preview`
- `reply_to_message_id`
- `force_reply`
- `selective`
- `protect_content`
- `extra`

`extra` is merged into Telegram request params and must contain serializable values.

## TelegramContext Rendering

`TelegramContext` wraps core rendering with Telegram options:

```php
$bot->command('html', static function (TelegramContext $telegram): void {
    $telegram->reply('<b>HTML</b>', TelegramMessageOptions::html());
});
```
