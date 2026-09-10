# Implementation Blueprint Template

Use this template after `BOT_SPEC.md` is complete. It converts product requirements into concrete `chatflowphp/telegram` implementation work.

Copy this file into the bot project as `IMPLEMENTATION_BLUEPRINT.md`.

## 1. Summary

```md
Bot:
Spec version/date:
Implementation goal:
Out of scope:
```

## 2. Bootstrap

| Decision | Value |
| --- | --- |
| Factory class | `App\Bot\BotFactory` |
| Storage | `FileStorage`, database storage, or custom storage |
| Runtime observer | `JsonlRuntimeObserver` path or custom observer |
| Error handler | Class/closure |
| Environment variables |  |
| Polling/webhook |  |

Factory responsibilities:

- Create `ChatFlow\Telegram\Bot`.
- Configure storage.
- Configure logger/runtime observer.
- Register services in the container.
- Register the flow.
- Avoid real network clients in tests.

## 3. Flow Registration

| Route Type | Input | Handler |
| --- | --- | --- |
| Command | `/start` |  |
| Command | `/help` |  |
| Action | `domain:action` |  |
| Action prefix | `domain:` |  |
| Text prefix |  |  |
| Text regex |  |  |
| Telegram event | `my_chat_member` |  |
| Media | `photo`, `document`, `any` |  |
| Fallback | any unmatched input |  |

Implementation rule:

- Register specific routes before broad prefix routes.
- Keep route handlers thin.
- Move business decisions into services.

## 4. Scenes

| Scene Class | Entry Point | Steps | Session Keys | Exit |
| --- | --- | --- | --- | --- |
| `CreateExampleScene` | `example:create` |  |  |  |

For every scene method:

| Method | Trigger | Reads | Writes | Output |
| --- | --- | --- | --- | --- |
| `handle` | scene active |  |  | prompt |
| `onConfirm` | scene action |  |  |  |

Use `BaseScene` for multi-step dialog state. Use plain route handlers for one-step actions.
Scenes are stateless: draft data lives in `$ctx->session()` and is cleared in `onLeave()` or
when the scene completes. Declare the allowed transitions between scenes with
`allowTransition()` so the runtime enforces the screen map.

## 5. Views And Actions

| View Builder | Screen Ids | Output |
| --- | --- | --- |
| `MainMenuView` | `main` | `View` |
| `ExampleView` |  |  |

Action constants:

```php
final class Actions
{
    public const MAIN = 'main:open';
    public const EXAMPLE_CREATE = 'example:create';
}
```

This is optional but recommended for larger bots.

## 6. Services And Repositories

| Service | Responsibility | Dependencies | Fake For Tests |
| --- | --- | --- | --- |
| `ExampleService` |  |  | `FakeExampleService` |
| `ReportService` |  |  |  |

Rules:

- Services should not depend on `Telegram\Bot\Api` unless they are explicitly Telegram infrastructure services.
- Repositories own persistence details.
- External clients should have test fakes.

## 7. Storage

Persistent data:

| Entity | Storage | Migration Needed | Notes |
| --- | --- | --- | --- |
|  |  | yes/no |  |

Session data:

| Key | Type | Writer | Reader | Cleanup |
| --- | --- | --- | --- | --- |
|  |  |  |  |  |

All session values must follow core serialization rules.

## 8. Telegram Layer

| Requirement | Tool | Location |
| --- | --- | --- |
| Force reply | `TelegramContext` |  |
| HTML/Markdown options | `TelegramView`, `TelegramMessageOptions` |  |
| File download | `Context::downloadAttachment()` |  |
| Cross-chat publishing | `TelegramPublisher` |  |
| Media groups | `TelegramMediaGroupCollector` |  |
| Screen cleanup | `TelegramScreenManager` |  |
| Membership events | `Bot::onTelegramEvent()` |  |

Use raw `Telegram\Bot\Api` only as an escape hatch and document the endpoint.

## 9. Error Handling

| Exception/Error | Handler | User Output | Log Context |
| --- | --- | --- | --- |
|  |  |  |  |

Register predictable domain exceptions with `onException()`. Use `setErrorHandler()` for final fallback behavior.

## 10. Tests

Acceptance tests:

| Test | Scenario | Key Assertions |
| --- | --- | --- |
| `StartFlowTest` | `/start` | text, keyboard |
|  |  |  |

Unit tests:

| Unit | Assertions |
| --- | --- |
| service |  |
| view builder |  |

Test rules:

- Use `TelegramBotTester` for conversational flows.
- Use `MockHttpClient` for Telegram API requests.
- Use memory storage or temporary storage.
- Do not call real Telegram APIs.
- Assert user-visible text and critical endpoint calls.

## 11. Operations

```md
Deployment mode:
Webhook URL:
Required env vars:
Storage path/database:
Log path:
Backup needs:
Manual recovery commands:
```

## 12. Implementation Order

1. Add tests for acceptance scenarios.
2. Add services and fakes.
3. Add views/actions.
4. Add scenes.
5. Register flow and error policy.
6. Add mock runner.
7. Run quality gates.

## 13. Quality Gates

```sh
composer validate --strict
composer check
composer cs-check
php examples/MiniShop/mock.php
```

For the bot project, also run its own mock scenario and deployment smoke test.
