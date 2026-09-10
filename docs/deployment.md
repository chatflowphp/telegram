# Deployment

## Environment

```sh
TELEGRAM_BOT_TOKEN=...
TELEGRAM_WEBHOOK_SECRET=...
CHATFLOW_RUNTIME_LOG=/var/log/chatflow/runtime.jsonl
```

The examples also accept `TELEGRAM_TOKEN`. The callback signing key is derived from the bot
token; pass `callbackSecret` to the `Bot` constructor to rotate it independently of the token.

## Storage

Configure persistent conversation storage for webhook deployments (`storage:` constructor
argument or `useStorage()`), with a session TTL that matches how long abandoned dialogs should
survive. Writable directories when the file stores are used:

```text
storage/<bot-name>/             conversation records (FileStorage)
storage/telegram-callbacks/     long callback payloads
storage/telegram-media-groups/  pending album parts
```

Expired callback payloads and album parts are removed opportunistically on write; run
`cleanupExpired()` from a cron job for deterministic cleanup.

## Webhook Deployment

1. Create the bot with storage and a webhook secret.
2. Register routes, scenes, transitions and middleware.
3. Expose an HTTPS endpoint that calls `runWebhook()`.
4. Call `setWebhook()` once during deployment.

## Polling Deployment

Run a long-lived PHP process under a supervisor:

```sh
php examples/MiniShop/run.php
```

## Observability

Pass a `RuntimeObserverInterface` to the bot constructor. `JsonlRuntimeObserver` records route
matches, scene transitions, queued and delivered effects, delivery failures, handler failures and
conversation resets as newline-delimited JSON.

## Delivery Failures

Remote media URLs, Telegram edit restrictions and Bot API errors can fail delivery.

- Log delivery failures (they are runtime events and `Result::error('delivery_failed')`).
- Prefer stable HTTPS media URLs or Telegram `file_id` for media.
- Use `TelegramPublisher` when message ids must be persisted.
- Handlers may run again after a rollback; keep side effects idempotent where possible.
