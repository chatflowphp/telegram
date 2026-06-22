# Webhook And Polling

## Webhook Handler

```php
require __DIR__ . '/vendor/autoload.php';

$bot = require __DIR__ . '/bootstrap-bot.php';
$result = $bot->runWebhook();
```

`runWebhook()` reads the update through Telegram SDK `getWebhookUpdate()` and handles it.

## Webhook Secret

Pass `webhookSecret` to the bot constructor. `runWebhook()` validates the incoming Telegram secret token header before handling the update.

```php
$bot = new Bot(
    token: $token,
    basePath: __DIR__,
    webhookSecret: $_ENV['TELEGRAM_WEBHOOK_SECRET']
);
```

## Set Webhook

```php
$bot->setWebhook('https://example.com/telegram/webhook', [
    'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
]);
```

If `webhookSecret` was configured, `setWebhook()` sends it as Telegram `secret_token`.

## Polling

```php
$bot->startPolling(timeout: 30);
```

Polling deletes the webhook before fetching updates.

It handles `SIGINT` and `SIGTERM` when the `pcntl` extension is available.

## Production Notes

- Use webhook for production when possible.
- Use polling for local development, demos and simple workers.
- Configure a real logger for polling loops.
- Ensure the storage path is writable by the PHP process.
