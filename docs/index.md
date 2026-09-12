# ChatFlow Telegram Docs

`chatflowphp/telegram` connects the platform-neutral `chatflowphp/core` runtime to the Telegram
Bot API. A chat is a state machine: scenes are its states, every update is one tick, and
navigation between screens is a transition that either commits or rolls back.

Use core APIs for business flow code and Telegram APIs only for Telegram-specific behaviour such
as force reply, file downloads, callback payload storage, media groups, polling, webhooks and
cross-chat publishing.

Coming from 1.x? Read [Upgrade From 1.x](upgrade-from-1.x.md).

## Build Your First Bot

1. [Getting Started](getting-started.md)
2. [Examples](examples.md)
3. [Testing](testing.md)

`StarterBot` is the minimal runnable example. `MiniShop` is the advanced example with a
declared screen map.

## Design A Production Bot

1. [Telegram Bot Development Kit](development/index.md)
2. [Bot Development Process](development/development-process.md)
3. [Telegram Bot Specification Template](development/bot-spec-template.md)
4. [Spec Review Checklist](development/spec-review-checklist.md)
5. [Implementation Blueprint Template](development/implementation-blueprint-template.md)
6. [Acceptance Testing Guide](development/acceptance-testing-guide.md)
7. [AI Development Workflow](development/ai-development-workflow.md)
8. [Monitoring Statistics Bot Spec Example](development/monitoring-bot-spec-example.md)

## Reference

1. [Installation](installation.md)
2. [Bot Lifecycle](bot-lifecycle.md)
3. [Commands And Text Routes](commands.md)
4. [Callbacks And Actions](callbacks.md)
5. [Rendering](rendering.md)
6. [Keyboards](keyboards.md)
7. [Telegram Context](telegram-context.md)
8. [Telegram Events](telegram-events.md)
9. [Scenes And Dialogs](scenes-dialogs.md)
10. [Group Chats](groups.md)
11. [Media And Files](media-files.md)
12. [Force Reply](force-reply.md)
13. [Media Groups](media-groups.md)
14. [Callback Payload Store](callback-payload-store.md)
15. [Payments And Updates Without A Chat](payments.md)
16. [Localization](localization.md)
17. [Publishing](publishing.md)
18. [Screen Manager](screen-manager.md)
19. [Middleware](middleware.md)
20. [Webhook And Polling](webhook-polling.md)
21. [Testing](testing.md)
22. [Deployment](deployment.md)
23. [Examples](examples.md)
24. [Upgrade From 1.x](upgrade-from-1.x.md)
25. [AI Index](ai-index.md)

## Core Subset For Telegram Authors

From `chatflowphp/core` read `Context`, `Routing`, `Scenes`, `Transitions`, `Views And
Effects`, `Storage`, `Validation` and `Localization`. `Architecture` and `Application` are for adapter authors.

## Main Classes

- `ChatFlow\Telegram\Bot`
- `ChatFlow\Telegram\TelegramPlatformAdapter`
- `ChatFlow\Telegram\TelegramContext`
- `ChatFlow\Telegram\TelegramMessageOptions`, `TelegramView`
- `ChatFlow\Telegram\TelegramPublisher`, `Media\TelegramMedia`, `Media\TelegramMediaSource`
- `ChatFlow\Telegram\Callback\TelegramCallbackPayloadEncoder`, `DecodedCallback`
- `ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector`
- `ChatFlow\Telegram\UI\TelegramScreenManager`
- `ChatFlow\Telegram\ConversationScope`
- `ChatFlow\Telegram\TelegramRateLimiter`, `TelegramText`
- `ChatFlow\Telegram\I18n\TelegramLocaleResolver`
- `ChatFlow\Telegram\Testing\TelegramBotTester`, `MockHttpClient`

## Boundary Rule

Default bot code uses `ChatFlow\Core\Context`, `View`, `Action`, `Choice`, scenes, transitions,
middleware and sessions. Telegram-specific classes are for force reply, Bot API message options,
immediate publishing that needs `message_id`, file download, media groups, membership updates
and message group cleanup.

## Relationship With Core

`Bot` implements `ChatFlow\Contracts\FlowRuntimeInterface`, so a flow registers commands,
routes, scenes, transitions, middleware and error policy against it. `TelegramPlatformAdapter`
turns Telegram updates into core inbound events and delivers core effects:

- `reply()` becomes a send operation;
- `render()` edits first where possible, falls back to delete and send, and treats an unchanged
  message as success;
- `ack()` answers callback queries when possible.

The core never imports the Telegram SDK.
