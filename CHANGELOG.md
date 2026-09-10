# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [2.0.0] - 2026-09-10

Adapts to `chatflowphp/core` 2.0 (scenes as states on `chatflowphp/automata` 2.0) and fixes the
issues found in the 1.x audit. No backward compatibility with 1.x; see `docs/upgrade-from-1.x.md`.

### Added

- Signed callback payloads (`cs:<signature>:<json>`); forged or unsigned payloads are rejected
  before any handler runs. `Bot` derives the key from the token; pass `callbackSecret` to
  override.
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

- `onMedia()` and `onTelegramEvent()` handlers run through the core `Application`: middleware,
  sessions, scenes, rollback and the runtime observer apply to them. Telegram event handlers are
  global routes; media handlers run only outside scenes.
- Updates without a chat (inline queries, polls, shipping queries) return
  `Result::noMatch('unsupported_update')` instead of creating conversation `0`.
- `render()` treats "message is not modified" as success and no longer deletes and resends the
  message.
- Edited messages that belong to an album keep their chat and sender.
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
