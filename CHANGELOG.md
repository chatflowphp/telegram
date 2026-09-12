# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Inbound events carry the Telegram message `date` (or `edit_date`) as `getOccurredAt()`.

## [2.0.0-rc1] - 2026-09-12

Adapts to `chatflowphp/core` 2.0 (scenes as states on `chatflowphp/automata` 2.0) and fixes the
issues found in the 1.x audit. No backward compatibility with 1.x; see `docs/upgrade-from-1.x.md`.

### Added

- `Bot::onRawUpdate()` dispatches updates that carry no chat (`pre_checkout_query`,
  `shipping_query`, `inline_query`, polls) to a handler with `Telegram\Bot\Api`, the raw update
  and the type injected, so Telegram payments can be completed. See `docs/payments.md`.
- `ConversationScope`: `ChatAndUser` gives every member of a group chat their own conversation
  while messages still go to the chat; `Bot::run()`, `enterScene()` and `leaveScene()` take the
  member with `userId:`, and `Bot::conversationIdFor()` exposes the key. See `docs/groups.md`.
- `I18n\TelegramLocaleResolver` and a default resolver binding, so
  `$bot->middleware([LocaleMiddleware::class])` makes a bot answer in the user's language. See
  `docs/localization.md`.
- `TelegramPlatformAdapter::chatIdFor()` resolves the chat a conversation belongs to, so delivery
  no longer assumes the conversation id is the chat id.
- The bundled examples cover the new capabilities: StarterBot speaks Russian and English with a
  language switch, and MiniShop pays a confirmed order with Telegram Stars end to end.
- `MockHttpClient` returns a message for `sendInvoice`, so payment flows can be tested offline.
- `TelegramRateLimiter` handles 429: a short `retry_after` is waited out and retried, a longer one
  becomes `TelegramRateLimitException`. Delivery reports `telegram_rate_limited` with the wait;
  the publisher raises so a broadcast can requeue. See `docs/deployment.md`.
- `TelegramText` and the Bot API text limits: replies over 4096 characters are split, captions
  keep what fits and continue as messages, acknowledgements are truncated, and the publisher
  raises `TelegramMessageTooLongException`. See `docs/rendering.md`.
- `MockHttpClient::rateLimitEndpoint()` simulates 429 with `retry_after`.

- Signed callback payloads (`cs:<signature>:["id",payload]`); forged or unsigned payloads are
  rejected before any handler runs and the query is answered silently. `Bot` derives the key
  from the token; pass `callbackSecret` to override. Stored payloads use content-addressed
  tokens, so re-rendering a button reuses its record.
- Every callback query is answered: the adapter implements `AfterHandleInterface` and answers
  queries no handler acknowledged.
- `Bot::run()`, `enterScene()`, `leaveScene()` and `TelegramBotTester::assertScenePending()` /
  `assertNoScenePending()` for acting on chats from schedulers and admin tools.
- `DecodedCallback` with accepted / expired / rejected statuses; expired stored payloads produce
  an event without an action (`callback_status` in the message ref).
- `Bot::verifyWebhookSecret()` (constant-time) and `runWebhook(?string $secretToken)`.
- `Bot` constructor options `storage`, `sessionTtlSeconds`, `debug`, `callbackSecret`;
  `useStorage($storage, $ttl)`.
- `Bot::allowTransition()`, `getScenes()`, `getTransitions()`, `getConversations()`,
  `getCallbackEncoder()`, `getAdapter()`.
- `TelegramBotTester`: `assertDontSee()`, `assertEndpointNotCalled()`, `assertSessionMissing()`,
  `assertResult()`, `conversation()`, `getLastResult()`; `clickButton()` signs payloads like
  rendered buttons; `assertScene()` accepts scene ids.
- `TelegramScreenManager` keeps tracked message ids in the conversation session, so cleanup works
  across webhook requests; `tracked()`.
- `TelegramPublisher::normalizeResponse()` is public.
- CI runs the example scenarios.

### Changed

- `Bot::onTelegramEvent()` throws on an update type it does not dispatch instead of accepting a
  handler that would never run.

- `onMedia()` and `onTelegramEvent()` handlers run through the core `Application`: middleware,
  sessions, scenes, rollback and the runtime observer apply to them. Telegram event handlers are
  global routes; media handlers run only outside scenes.
- Updates without a chat (inline queries, polls, shipping queries) return
  `Result::noMatch('unsupported_update')` instead of creating conversation `0`.
- `render()` treats "message is not modified" as success and no longer deletes and resends the
  message.
- Edited messages that belong to an album keep their chat and sender. Album collection elects one
  leader per album (`TelegramMediaGroupStoreInterface::claim()`), so only one webhook worker
  waits for the collection window.
- `TelegramCallbackStoreInterface::put()` takes the token chosen by the encoder.
- The file-backed callback and media group stores create their directories lazily and clean up
  with a small probability on write (2% and 5%) instead of scanning on every construction.
- `Bot` builds the core `Application` on the first handled update; `useStorage()` after that
  throws. `ExceptionRegistry` receives `debug` instead of a config object.
- Polling sends `allowed_updates` only when configured.
- Tooling: PHPStan level max with strict rules, PER-CS 2.0, strict PHPUnit configuration,
  prefer-lowest CI job, Dependabot.

### Removed

- `Config` usage, `Bot::getConfig()`, `Bot::addProvider()` and the `ProviderInterface`
  dependency (removed from core).
- Unsigned inline JSON callback data.
- `Bot::getStateManager()`; use `getConversations()`.

## [1.0.2] - 2026-08-30

Last release of the 1.x line.

[Unreleased]: https://github.com/chatflowphp/telegram/compare/2.0.0...HEAD
[2.0.0]: https://github.com/chatflowphp/telegram/compare/1.0.2...2.0.0
[1.0.2]: https://github.com/chatflowphp/telegram/releases/tag/1.0.2
