# Bot Development Process

This guide describes a repeatable process for building production Telegram bots with `chatflowphp/telegram`.

The goal is to turn a product idea into three implementation-ready artifacts:

- `BOT_SPEC.md`: product, Telegram UX, entities, scenes, actions and acceptance scenarios.
- `IMPLEMENTATION_BLUEPRINT.md`: concrete ChatFlow classes, routes, scenes, services, storage and tests.
- `TelegramBotTester` scenarios: executable user journeys that prove the bot works without real Telegram calls.

## Process

### 1. Define The Product Contract

Start with [Bot Spec Template](bot-spec-template.md). Do not start with PHP classes.

The spec must answer:

- What business result the bot must produce.
- Which user roles exist and what each role may do.
- Which persistent entities exist.
- Which Telegram screens, commands, callback buttons and dialogs exist.
- Which integrations and Telegram-specific features are required.
- Which user journeys must pass before the bot is accepted.

A good spec is precise enough that an implementer does not need to invent action ids, screen behavior or validation rules while coding.

### 2. Convert The Spec To A Blueprint

Use [Implementation Blueprint Template](implementation-blueprint-template.md) after the spec is stable.

The blueprint maps product language to ChatFlow implementation decisions:

- `BotFactory`, storage and runtime observer.
- Flow registration: commands, action routes, scenes, middleware and error policy.
- Scene classes and their step handlers.
- Business services and repositories.
- Telegram-specific tools such as `TelegramContext`, `TelegramPublisher`, media groups and membership events.
- Mock acceptance tests and service fakes.

This document is the handoff point between planning and coding.

### 3. Write Acceptance Tests First

Each important user journey in the spec should become a `TelegramBotTester` test.

Use tests to lock:

- First screen after `/start`.
- Callback navigation.
- Scene prompts and validation errors.
- Session changes.
- Telegram endpoints used by special behavior.
- User-facing error messages.

Do not use real Telegram network calls in acceptance tests.

### 4. Implement The Flow

Default to core ChatFlow primitives:

- `ChatFlow\Core\Context`
- `ChatFlow\View\View`
- `ChatFlow\View\Action`
- scenes, middleware and sessions

Use Telegram-specific APIs only where the feature cannot be expressed through the core contract:

- force reply
- Telegram message options
- Telegram file download
- media group handling
- membership events
- immediate cross-chat publishing that must return Telegram `message_id`
- best-effort screen cleanup

### 5. Verify The Package

Before shipping a bot implementation, run:

```sh
composer validate --strict
composer check
composer cs-check
php examples/MiniShop/mock.php
```

For the bot project itself, also run its own mock runner and deployment smoke test.

## Recommended Project Artifacts

For every production bot, keep these files in the bot repository:

- `docs/BOT_SPEC.md`
- `docs/IMPLEMENTATION_BLUEPRINT.md`
- `docs/OPERATIONS.md`
- `tests/Acceptance/*Test.php`
- `examples/mock.php` or `bin/mock.php`

The spec and blueprint should evolve with the bot. If behavior changes without updating them, AI-assisted maintenance becomes unreliable.

## Definition Of Ready

A Telegram bot is ready for implementation when:

- All screens have stable text, action ids and navigation behavior.
- All dialogs have prompts, validation rules and error messages.
- All external integrations have clear success and failure behavior.
- All Telegram-only features are explicitly marked.
- Acceptance scenarios cover the main happy path and at least the main failure paths.
- Open questions are either resolved or intentionally deferred.

## Definition Of Done

A bot feature is done when:

- User-facing behavior matches the spec.
- Acceptance tests pass with `TelegramBotTester`.
- No normal reply/render logic depends on raw `Telegram\Bot\Api`.
- Session and action payloads contain only serializable values.
- Errors have user-facing messages where needed and logs where useful.
- Runtime checks pass locally and in CI.
