# ChatFlow Telegram Docs

`chatflowphp/telegram` is the active production adapter for ChatFlow.

It connects the platform-neutral `chatflowphp/core` runtime to the Telegram Bot API. Use core APIs for business flow code and Telegram APIs only for Telegram-specific behavior such as force reply, file downloads, callback payload storage, media groups, polling, webhooks and cross-chat publishing.

## Build Your First Bot

Start here if you are new to ChatFlow Telegram:

1. [Getting Started](getting-started.md)
2. [Examples](examples.md)
3. [Testing](testing.md)

`StarterBot` is the minimal runnable example. `MiniShop` is the advanced example.

## Design A Production Bot

Use this path when you are planning a real product bot with explicit specs and acceptance tests:

1. [Telegram Bot Development Kit](development/index.md)
2. [Bot Development Process](development/development-process.md)
3. [Telegram Bot Specification Template](development/bot-spec-template.md)
4. [Spec Review Checklist](development/spec-review-checklist.md)
5. [Implementation Blueprint Template](development/implementation-blueprint-template.md)
6. [Acceptance Testing Guide](development/acceptance-testing-guide.md)
7. [AI Development Workflow](development/ai-development-workflow.md)
8. [Monitoring Statistics Bot Spec Example](development/monitoring-bot-spec-example.md)

## Browse API And Reference

Use these pages once you know which part of the runtime you need:

1. [Installation](installation.md)
2. [Bot Lifecycle](bot-lifecycle.md)
3. [Commands And Text Routes](commands.md)
4. [Callbacks And Actions](callbacks.md)
5. [Rendering](rendering.md)
6. [Keyboards](keyboards.md)
7. [Telegram Context](telegram-context.md)
8. [Telegram Events](telegram-events.md)
9. [Scenes And Dialogs](scenes-dialogs.md)
10. [Media And Files](media-files.md)
11. [Force Reply](force-reply.md)
12. [Media Groups](media-groups.md)
13. [Callback Payload Store](callback-payload-store.md)
14. [Publishing](publishing.md)
15. [Screen Manager](screen-manager.md)
16. [Middleware](middleware.md)
17. [Webhook And Polling](webhook-polling.md)
18. [Testing](testing.md)
19. [Deployment](deployment.md)
20. [Examples](examples.md)
21. [AI Index](ai-index.md)

## Core Subset For Telegram Authors

When you need core background, read only this subset from `chatflowphp/core`:

1. `Context`
2. `Routing`
3. `Views And Effects`
4. `Scenes`
5. `Storage`
6. `Validation`
7. `Testing`

`Architecture`, `Application` and lower-level runtime internals are optional for normal Telegram bot development.

## Main Classes

- `ChatFlow\Telegram\Bot`
- `ChatFlow\Telegram\TelegramPlatformAdapter`
- `ChatFlow\Telegram\TelegramContext`
- `ChatFlow\Telegram\TelegramMessageOptions`
- `ChatFlow\Telegram\TelegramView`
- `ChatFlow\Telegram\TelegramPublisher`
- `ChatFlow\Telegram\Media\TelegramMedia`
- `ChatFlow\Telegram\Media\TelegramMediaSource`
- `ChatFlow\Telegram\Callback\TelegramCallbackPayloadEncoder`
- `ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector`
- `ChatFlow\Telegram\UI\TelegramScreenManager`
- `ChatFlow\Telegram\Testing\TelegramBotTester`

## Boundary Rule

Default bot code should use `ChatFlow\Core\Context`, `View`, `Action`, `Choice`, scenes, middleware and sessions.

Use Telegram-specific classes only when the feature cannot be represented by the core contract:

- Telegram force reply.
- Telegram Bot API message options.
- Immediate publishing that must return Telegram `message_id`.
- Telegram file download.
- Telegram media groups.
- Telegram membership update handling.
- Best-effort cleanup of Telegram message groups.

## Relationship With Core

`Bot` implements `ChatFlow\Contracts\FlowRuntimeInterface`, so a flow can register commands, routes, scenes, middleware and error policy against it.

`TelegramPlatformAdapter` converts Telegram updates to typed core inbound events and delivers core outbound effects:

- `reply()` becomes a Telegram send operation.
- `render()` tries edit first where possible and falls back to delete/send or send.
- `ack()` answers callback queries when possible.

The core package does not import Telegram SDK classes.

## Development Methodology

For production bots, write `BOT_SPEC.md` and `IMPLEMENTATION_BLUEPRINT.md` before coding.

The recommended implementation flow is:

1. Describe the product, screens, actions, scenes, integrations and acceptance scenarios.
2. Convert the spec into ChatFlow routes, scenes, services, storage and tests.
3. Implement `TelegramBotTester` acceptance tests.
4. Implement the bot.
5. Run package and bot quality gates.

Use [Monitoring Statistics Bot Spec Example](development/monitoring-bot-spec-example.md) as a reference for complex bot specifications.
