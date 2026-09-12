# ChatFlow Telegram

[![CI](https://github.com/chatflowphp/telegram/actions/workflows/ci.yml/badge.svg)](https://github.com/chatflowphp/telegram/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg)](https://phpstan.org/)
[![PHPUnit](https://img.shields.io/badge/PHPUnit-tested-brightgreen.svg)](https://phpunit.de/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

`chatflowphp/telegram` is the Telegram adapter for the ChatFlow runtime. Every chat is a state
machine: screens and dialog steps are scenes, each update is one tick, and navigation between
them is a transition that either commits or rolls back.

It supports webhook and polling execution, commands (including `/command@bot` in groups),
signed callback buttons, smart edit-or-send rendering, media views, callback acknowledgements,
file downloads, media groups and a typed Telegram layer for admin-style bots.

Documentation starts at [docs/index.md](docs/index.md). Coming from 1.x? Read
[docs/upgrade-from-1.x.md](docs/upgrade-from-1.x.md).

## Quick Start

```php
use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\Scene\RootScene;
use ChatFlow\Storage\Drivers\FileStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\View\Action;
use ChatFlow\View\View;

final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $ctx->ask('Send your phone in +79991234567 format')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->onText('cancel', 'onCancel')
            ->handle('savePhone');
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('phone', $ctx->getText());
        $ctx->reply('Saved');
        $ctx->leave();
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->leave();
    }
}

$bot = new Bot($_ENV['TELEGRAM_BOT_TOKEN'], __DIR__, storage: new FileStorage(__DIR__ . '/storage/bot'));

$bot->registerScene(PhoneScene::class);
$bot->allowTransition(RootScene::ID, PhoneScene::class);

$bot->command('start', static function (Context $ctx): void {
    $ctx->reply(View::text('Hello')->addActionRow(new Action('phone:edit', 'Set phone')));
});

$bot->onAction('phone:edit', static function (Context $ctx): void {
    $ctx->ack();
    $ctx->enter(PhoneScene::class);
});

$bot->runWebhook();
```

`/start` sent while the phone scene is active runs the command and keeps the scene; `cancel`
leaves it; anything else asks again. See [examples/StarterBot](examples/StarterBot) for the
runnable version and [examples/MiniShop](examples/MiniShop) for a larger bot with a declared
screen map.

## Mapping

- Telegram messages become text events, callback queries become action events, media messages
  become inbound attachments.
- The chat id is the conversation id and the storage key.
- `reply()` sends a new message. `render()` edits the current message where Telegram allows it,
  otherwise deletes and sends. An unchanged screen is a no-op.
- `ack()` answers the callback query; with text and no callback it sends a message.
- Updates without a chat (inline queries, polls, shipping queries) are skipped with
  `Result::noMatch('unsupported_update')`.

## Telegram-Specific Layer

Keep handlers on `ChatFlow\Core\Context`. Use `ChatFlow\Telegram\TelegramContext` for force
reply, Telegram message options and update metadata; inject `TelegramPublisher` when a handler
must obtain Telegram message ids immediately; inject `Telegram\Bot\Api` for endpoints ChatFlow
does not model.

```php
$bot->command('ask', static function (TelegramContext $telegram): void {
    $telegram->forceReply('Send the updated post text');
});

$bot->onTelegramEvent('my_chat_member', static function (TelegramContext $telegram, array $update): void {
    $telegram->reply('Membership changed');
});
```

Callback payloads are signed with a key derived from the bot token (or `callbackSecret`) and
verified on every callback query. Payloads that do not fit into 64 bytes are stored behind a
random token. Forged callback data is rejected before it reaches any handler.

## Examples And Tests

```sh
php examples/StarterBot/mock.php
php examples/MiniShop/mock.php
composer check
```

`TelegramBotTester` and `MockHttpClient` drive a bot without network access and assert on the
Telegram requests it makes and on the conversation state.

## Requirements

PHP 8.2 or newer, `chatflowphp/core` 2.x, `irazasyed/telegram-bot-sdk` 3.16 or newer.

## License

MIT. See [LICENSE](LICENSE).
