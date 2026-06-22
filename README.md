# ChatFlow Telegram

[![CI](https://github.com/chatflowphp/telegram/actions/workflows/ci.yml/badge.svg)](https://github.com/chatflowphp/telegram/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-tested-brightgreen.svg)](https://phpunit.de/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

`chatflowphp/telegram` is the Telegram adapter for the platform-neutral ChatFlow core.

It supports webhook and polling execution, commands, callback actions, smart render/edit-or-send behavior, media views, callback acknowledgements, Telegram file downloads and a typed Telegram layer for admin-style bots.

Full package documentation starts at [docs/index.md](docs/index.md). Use [docs/ai-index.md](docs/ai-index.md) as compact context for AI-assisted bot implementation tasks.

## Quick Start

```php
use ChatFlow\Core\Context;
use ChatFlow\Telegram\Bot;
use ChatFlow\View\Action;
use ChatFlow\View\View;

$bot = new Bot($_ENV['TELEGRAM_BOT_TOKEN'], __DIR__);

$bot->command('start', static function (Context $ctx): void {
    $ctx->reply(
        View::text('Hello from ChatFlow Telegram')
            ->addActionRow(new Action('menu:open', 'Open menu'))
    );
});

$bot->prefix('menu:', static function (Context $ctx): void {
    $ctx->ack();
    $ctx->render(View::text('Menu opened'));
});

$bot->runWebhook();
```

## Mapping

- Telegram messages become text events.
- Callback queries become action events.
- Chat id becomes `ConversationRef::getId()` and is used as the session key.
- Telegram message/callback metadata is stored in `MessageRef` for adapter delivery.
- `reply()` sends a new message.
- `render()` edits where possible, otherwise deletes/sends.
- `ack()` answers callback queries and falls back to a message when text is provided.

## Telegram-Specific Layer

Keep portable handler code on `ChatFlow\Core\Context`. Use `ChatFlow\Telegram\TelegramContext` only when the flow needs Telegram-only behavior:

```php
use ChatFlow\Telegram\TelegramContext;

$bot->command('ask', static function (TelegramContext $telegram): void {
    $telegram->forceReply('Send the updated post text');
});

$bot->onTelegramEvent('my_chat_member', static function (TelegramContext $telegram, array $update): void {
    $telegram->reply('Membership changed');
});
```

`TelegramContext` is intentionally not a facade for the Telegram Bot API. It only adds ChatFlow-aware Telegram helpers such as `forceReply()`, Telegram message options and current update metadata. Use core `ack()` for callback acknowledgements.

For publishing flows that need Telegram `message_id` immediately, inject `ChatFlow\Telegram\TelegramPublisher` and call `sendMessage()`, `sendMedia()`, `sendMediaGroup()`, `editText()`, `editCaption()` or `deleteMessage()` directly. Publisher methods return typed delivery results with chat id, message ids, endpoint and raw response. For Telegram Bot API endpoints not covered by ChatFlow semantics, inject `Telegram\Bot\Api` and use the SDK directly.

`TelegramView` and `TelegramMessageOptions` attach Telegram Bot API options to a neutral `View` via metadata:

```php
use ChatFlow\Telegram\TelegramView;

$ctx->reply(TelegramView::forceReply('Reply with details'));
```

Long callback payloads are stored automatically behind short `cf:<token>` callback data. Inbound callback queries are decoded back to the original action id and payload.

Inbound Telegram attachments are normalized for photos, documents, videos, animations, audio, voice, stickers and video notes. Telegram `file_id`, `file_unique_id`, captions, media group id, dimensions, duration, mime type and size are preserved in attachment metadata.

Media groups are collected by the adapter before core handling. The default collector waits briefly, aggregates updates with the same `media_group_id`, and emits one inbound event with ordered attachments.

`TelegramScreenManager` provides best-effort cleanup for command screens by tracking publisher delivery results and deleting previous message groups.

## Operational Notes

The default file-backed callback payload store keeps long callback payloads for 7 days and removes expired tokens opportunistically. The default media group store keeps pending album parts for 5 minutes and also cleans stale groups opportunistically.

Telegram `render()` is intentionally best-effort: the adapter tries to edit the current message, then falls back to delete/send or send-only when Telegram rejects the edit. These fallbacks are logged through the `Bot` logger but do not fail the flow unless final delivery also fails.

The MiniShop example is Telegram-specific and demonstrates commands, inline callback buttons, smart edit-or-send rendering, media replies, scenes, validation and sessions.

```sh
php examples/MiniShop/mock.php
```

## Observability

Pass `runtimeObserver` to the `Bot` constructor to receive core lifecycle events such as route matches, queued effects and delivery failures.

The MiniShop polling example enables JSONL runtime logs by default:

```sh
TELEGRAM_BOT_TOKEN=... php examples/MiniShop/run.php
tail -f storage/minishop/runtime.jsonl
```

Set `CHATFLOW_RUNTIME_LOG=/path/to/runtime.jsonl` to override the log path.

## Documentation

- [Installation](docs/installation.md)
- [Bot Lifecycle](docs/bot-lifecycle.md)
- [Commands](docs/commands.md)
- [Callbacks](docs/callbacks.md)
- [Rendering](docs/rendering.md)
- [Telegram Context](docs/telegram-context.md)
- [Telegram Events](docs/telegram-events.md)
- [Scenes And Dialogs](docs/scenes-dialogs.md)
- [Media And Files](docs/media-files.md)
- [Middleware](docs/middleware.md)
- [Testing](docs/testing.md)
- [AI Index](docs/ai-index.md)

## License

This project is released under the MIT License. See `LICENSE` for details.
