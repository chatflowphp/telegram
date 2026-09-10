# Callback Payload Store

Telegram limits callback data to 64 bytes and does not verify that the data a client sends
matches a button the bot rendered. `TelegramCallbackPayloadEncoder` solves both.

## Encoding Rules

| Action | Callback data |
| --- | --- |
| no payload, id fits | `cart:open` |
| payload fits when signed | `cs:<12 hex chars>:{"id":"cart:add","payload":{"id":10}}` |
| otherwise | `cf:<16 hex token>` pointing to the stored `['id' => ..., 'payload' => ...]` |

The signature is HMAC-SHA256 over the JSON, truncated to 12 hex characters. `Bot` derives the
key from the bot token; pass `callbackSecret` to the constructor to use your own.

## Decoding

`decode()` returns a `DecodedCallback`:

| Status | Meaning | Effect |
| --- | --- | --- |
| `accepted` | plain id, valid signature or resolved token | normal action event |
| `expired` | `cf:` token no longer stored | event without action; `callback_status` = `expired` in the message ref |
| `rejected` | unsigned JSON, bad signature, malformed data | the update is skipped with `Result::noMatch('unsupported_update')` |

Handle expired buttons in a fallback route or in the active scene's `handle()`, for example by
re-rendering the screen.

## Stores

- `FileTelegramCallbackStore($directory, ttlSeconds: 604800, cleanupProbability: 2)` is the
  default. Files are created on first use and expired ones are removed on write with the given
  probability; call `cleanupExpired()` from a cron job for deterministic cleanup.
- `InMemoryTelegramCallbackStore` for tests.
- Implement `TelegramCallbackStoreInterface` for Redis or a database.

## Payload Rules

Payloads must be serializable under core rules: scalars, `null`, arrays of those, backed enums.
Prefer ids over data: `['id' => 10]`, not the whole product.
