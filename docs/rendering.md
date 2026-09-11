# Rendering

Telegram output is described with core `View` objects and delivered by the Telegram adapter.

If you are new to the package, start with [Getting Started](getting-started.md) and come back here when you need the output model in more detail.

## Choosing `reply()`, `render()` Or `ack()`

| Method | Use When | Telegram Result |
| --- | --- | --- |
| `reply()` | You want a new message in the chat | `sendMessage`, `sendPhoto`, `sendDocument`, or another send endpoint |
| `render()` | You are updating the current logical screen | Try edit first, then fall back to delete/send or send |
| `ack()` | You want lightweight feedback for a callback button | `answerCallbackQuery()` when available, otherwise a normal message if text was provided |

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

When Telegram reports that the message is not modified (the user pressed a button that renders
the same screen), the render is treated as success and nothing is deleted or resent.

Outside a callback there is no message of the bot's to edit: after a text message, inside a scene
step or during a system tick, `render()` sends a new message. Use it for the screen the user is
looking at, and `reply()` when a new message is what you mean.

Other render fallback failures are logged. The flow fails only if final delivery fails.

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
