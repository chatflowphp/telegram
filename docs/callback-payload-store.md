# Callback Payload Store

Telegram limits callback data to 64 bytes and does not verify that the data a client sends
matches a button the bot rendered. `TelegramCallbackPayloadEncoder` solves both.

## Encoding Rules

| Action | Callback data |
| --- | --- |
| no payload, id fits | `cart:open` |
| payload fits when signed | `cs:<10 hex chars>:["cart:add",{"id":10}]` |
| otherwise | `cf:<16 hex token>` pointing to the stored `['id' => ..., 'payload' => ...]` |

The signature is HMAC-SHA256 over the JSON list, truncated to 10 hex characters, which leaves
50 bytes for the id and payload. Tokens are derived from the payload and the secret as well, so
rendering the same button again reuses the stored record instead of creating a new one. `Bot`
derives the key from the bot token; pass `callbackSecret` to the constructor to use your own.

## Decoding

`decode()` returns a `DecodedCallback`:

| Status | Meaning | Effect |
| --- | --- | --- |
| `accepted` | plain id, valid signature or resolved token | normal action event |
| `expired` | `cf:` token no longer stored | event without action; `callback_status` = `expired` in the message ref |
| `rejected` | unsigned JSON, bad signature, malformed data | the update is skipped with `Result::noMatch('unsupported_update')`; the callback query is answered silently |

Handle expired buttons in a fallback route or in the active scene's `handle()`, for example by
re-rendering the screen.

## Stores

- `FileTelegramCallbackStore($directory, ttlSeconds: 604800, cleanupProbability: 2)` is the
  default. Files are created on first use, storing a token again restarts its TTL, and expired
  files are removed on write with the given probability; call `cleanupExpired()` from a cron job
  for deterministic cleanup.
- `InMemoryTelegramCallbackStore` for tests.
- Implement `TelegramCallbackStoreInterface` for Redis or a database.

## Payload Rules

Payloads must be serializable under core rules: scalars, `null`, arrays of those, backed enums.
Prefer ids over data: `['id' => 10]`, not the whole product.
