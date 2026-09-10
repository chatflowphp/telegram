# Upgrade From 1.x

2.0 depends on `chatflowphp/core` 2.0. Read the core guide first:
`chatflowphp/core` `docs/upgrade-from-1.x.md` covers scenes, sessions, navigation and storage.
This page lists what changes in the Telegram adapter itself.

Stored 1.x sessions are discarded on first access.

## Bot Construction

| 1.x | 2.0 |
| --- | --- |
| `new Bot($token, $basePath, config: $config, ...)` | `new Bot($token, $basePath, storage: ..., sessionTtlSeconds: ..., debug: ..., callbackSecret: ...)` |
| `$bot->useStorage($storage)` at any time | `$bot->useStorage($storage, $ttl)` before the first update |
| `$bot->getConfig()` | removed; read your environment yourself |
| `$bot->addProvider($provider)` | removed; register services on `$bot->getContainer()` |
| `$bot->getStateManager()` | `$bot->getConversations()` |
| `$bot->getApplication()` before configuration | builds the application; configure first |

Scenes work without `useStorage()` using in-memory storage. Configure persistent storage for
webhook deployments.

## Scenes

Scene code follows the core 2.0 API: `$ctx->ask()`, `$ctx->leave()`, `$ctx->back()`, stateless
scenes, `ChatFlow\Scene\BaseScene`. Declare the screen map with `allowTransition()` when the bot
spec has one.

## Callbacks

- Payloads are signed. Callback data produced by 1.x buttons (`{"id":...,"payload":...}`) is
  rejected; old `cf:<token>` data is decoded as long as the token is still stored.
- `TelegramCallbackPayloadEncoder` requires a secret: `new TelegramCallbackPayloadEncoder($store, $secret)`.
- `decode()` returns `DecodedCallback` instead of a two-element array.
- In tests, `TelegramBotTester::clickButton()` signs payloads automatically; raw JSON via
  `clickCallbackData()` is rejected like any forged payload.

## Handlers

- `onMedia()` and `onTelegramEvent()` handlers run through middleware and have session access.
  Media handlers no longer run while a scene is active; the scene receives the attachment.
- Commands are global: `/start` inside a scene runs the command route. Return `false` from
  `allowsGlobalRoutes()` in a scene to keep 1.x behaviour.
- Handlers receive `TelegramContext` by type hint. The `telegram` parameter name alias is gone.

## Rendering

`render()` on an unchanged message is a no-op success instead of delete-and-send.

## Webhook

`runWebhook()` accepts the secret token header as an argument for frameworks that do not
populate `$_SERVER`; comparison is constant-time.

## Stores

`FileTelegramCallbackStore` and `FileTelegramMediaGroupStore` no longer scan their directories
in the constructor. Cleanup runs on write with the configured probability (defaults 2 and 5
percent); call `cleanupExpired()` from a cron job for deterministic cleanup.
