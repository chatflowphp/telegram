# Installation

Install the Telegram adapter in an application:

```sh
composer require chatflowphp/telegram
```

The package depends on `chatflowphp/core` and `irazasyed/telegram-bot-sdk`.

## Requirements

- PHP `^8.2`.
- A Telegram bot token from BotFather.
- Writable storage directory when sessions, callback payload storage or media group collection are used.

## Minimal Bot

```php
use ChatFlow\Core\Context;
use ChatFlow\Telegram\Bot;

require __DIR__ . '/vendor/autoload.php';

$bot = new Bot(
    token: $_ENV['TELEGRAM_BOT_TOKEN'],
    basePath: __DIR__,
);

$bot->command('start', static function (Context $ctx): void {
    $ctx->reply('Hello from ChatFlow');
});

$bot->runWebhook();
```

## Storage

Scenes and sessions require a storage implementation:

```php
use ChatFlow\Storage\Drivers\FileStorage;

$bot->useStorage(new FileStorage(__DIR__ . '/storage/bot'));
```

The default Telegram callback payload store uses:

```text
<basePath>/storage/telegram-callbacks
```

The default Telegram media group store uses:

```text
<basePath>/storage/telegram-media-groups
```

## Local Development

For package development in this workspace:

```sh
composer install
composer check
php examples/StarterBot/mock.php
php examples/MiniShop/mock.php
```
