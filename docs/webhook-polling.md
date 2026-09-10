# Webhook And Polling

## Webhook

```php
require __DIR__ . '/vendor/autoload.php';

$bot = require __DIR__ . '/bootstrap-bot.php';
$bot->runWebhook();
```

`runWebhook()` reads the update through the SDK's `getWebhookUpdate()` and handles it. With a
framework request object, pass the header explicitly:

```php
$bot->runWebhook($request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token'));
```

## Webhook Secret

```php
$bot = new Bot(token: $token, basePath: __DIR__, webhookSecret: $_ENV['TELEGRAM_WEBHOOK_SECRET']);
```

`runWebhook()` compares the secret token header in constant time and throws
`ValidationException` on mismatch. `setWebhook()` sends the secret to Telegram:

```php
$bot->setWebhook('https://example.com/telegram/webhook', [
    'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
]);
```

## Polling

```php
$bot->startPolling(timeout: 30, allowedUpdates: ['message', 'callback_query']);
```

Polling deletes the webhook, then loops over `getUpdates()`. Album parts that arrive in the same
batch are grouped without waiting. `SIGINT` and `SIGTERM` stop the loop when `pcntl` is
available. Errors are logged and the loop continues after one second.

## Production Notes

- Use webhook in production, polling for development, demos and simple workers.
- Configure persistent storage (`storage:` constructor argument or `useStorage()`); the default
  in-memory storage forgets scenes between webhook requests.
- Configure a real logger for polling loops.
- Make the storage path writable by the PHP process.
