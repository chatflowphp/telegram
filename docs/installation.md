# Installation

```sh
composer require chatflowphp/telegram
```

The package depends on `chatflowphp/core` 2.x, `chatflowphp/automata` 2.x and
`irazasyed/telegram-bot-sdk`.

## Requirements

- PHP 8.2 or newer.
- A bot token from BotFather.
- A writable directory for conversation storage, callback payloads and media group parts.

## Minimal Bot

```php
use ChatFlow\Core\Context;
use ChatFlow\Storage\Drivers\FileStorage;
use ChatFlow\Telegram\Bot;

require __DIR__ . '/vendor/autoload.php';

$bot = new Bot(
    token: $_ENV['TELEGRAM_BOT_TOKEN'],
    basePath: __DIR__,
    storage: new FileStorage(__DIR__ . '/storage/bot'),
);

$bot->command('start', static function (Context $ctx): void {
    $ctx->reply('Hello from ChatFlow');
});

$bot->runWebhook();
```

## Storage

Conversations (current scene, session data, history) are stored through
`ChatFlow\Storage\StorageInterface`. Without the `storage` argument the bot uses in-memory
storage, which is enough for polling processes and tests but forgets everything between webhook
requests. Drivers: `FileStorage`, `RedisStorage`, `DatabaseStorage`, `MemoryStorage`.

```php
$bot->useStorage(new RedisStorage($redis), sessionTtlSeconds: 86400);
```

Default Telegram stores under `basePath`:

```text
storage/telegram-callbacks       long callback payloads
storage/telegram-media-groups    pending album parts
```

## Local Development

```sh
composer install
composer check
php examples/StarterBot/mock.php
php examples/MiniShop/mock.php
```
