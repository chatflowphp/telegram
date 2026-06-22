# Deployment

## Environment

Recommended variables:

```sh
TELEGRAM_BOT_TOKEN=...
TELEGRAM_WEBHOOK_SECRET=...
CHATFLOW_RUNTIME_LOG=/var/log/chatflow/runtime.jsonl
```

The MiniShop example accepts `TELEGRAM_TOKEN` as a convenience alias.

## Writable Directories

Make sure these directories are writable when used:

```text
storage/
storage/telegram-callbacks/
storage/telegram-media-groups/
storage/<bot-name>/
```

## Webhook Deployment

1. Create the bot.
2. Configure storage.
3. Register routes/scenes/middleware.
4. Expose a HTTPS endpoint.
5. Call `setWebhook()` once during deployment.
6. Let the endpoint call `runWebhook()`.

## Polling Deployment

Run a long-lived PHP process:

```sh
php examples/MiniShop/run.php
```

Use a supervisor/systemd process manager in production.

## Observability

Pass a `RuntimeObserverInterface` to the bot constructor. `JsonlRuntimeObserver` is useful for local debugging and production incident analysis:

```php
new JsonlRuntimeObserver(__DIR__ . '/storage/runtime.jsonl')
```

Runtime logs can show route matching, queued effects, delivery failures and handler failures.

## Delivery Failures

Remote media URLs, Telegram edit restrictions and Bot API errors can fail delivery.

Recommended production policy:

- Log delivery failures.
- Prefer stable HTTPS media URLs or Telegram `file_id` for media.
- Use `TelegramPublisher` when message ids must be persisted.
- Keep handler code idempotent where possible.
