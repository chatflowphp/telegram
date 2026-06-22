# ChatFlow Telegram Docs

`chatflowphp/telegram` is the active production adapter for ChatFlow.

It connects the platform-neutral `chatflowphp/core` runtime to the Telegram Bot API. Use core APIs for business flow code and Telegram APIs only for Telegram-specific behavior such as force reply, file downloads, callback payload storage, media groups, polling, webhooks and cross-chat publishing.

## Reading Order

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
