# Telegram Bot Specification Template

Use this template before implementation. It is written for developers and AI agents that will build the bot with `chatflowphp/telegram`.

Copy this file into the bot project as `BOT_SPEC.md` and fill every required section.

## 1. Product Goal

Describe the business problem and measurable outcome.

```md
Bot name:
Primary goal:
Target users:
Business outcome:
Non-goals:
```

## 2. Telegram UX Model

Define the primary interaction style.

```md
Primary UX:
- Menu navigation
- Dialogs/scenes
- Admin panel
- Notifications
- Publishing flow

Rendering policy:
- Use `reply()` for new messages when:
- Use `render()` for screen replacement when:
- Use `ack()` for callback feedback when:
```

## 3. Roles And Permissions

| Role | Can Do | Cannot Do | Identification |
| --- | --- | --- | --- |
| User |  |  | Telegram user id |
| Admin |  |  | Config allow-list |
| Moderator |  |  |  |

Permission failures:

| Case | User Message | Log Context |
| --- | --- | --- |
| Unauthorized command |  |  |

## 4. Entities

| Entity | Fields | Created By | Updated By | Persistence |
| --- | --- | --- | --- | --- |
| ExampleEntity | `id`, `status`, `createdAt` |  |  | DB table |

Session keys:

| Key | Type | Owner | Cleared When |
| --- | --- | --- | --- |
| `draft.example` | array | Scene | Scene completes |

Session values and action payloads must be serializable:

```text
Allowed: scalar, null, arrays of allowed values, backed enum values.
Not allowed: objects, resources, closures, non-backed enums.
```

## 5. Screen Map

Every screen must have stable text, actions and rendering behavior.

| Screen Id | Text/Purpose | Actions | Render Mode | Handler |
| --- | --- | --- | --- | --- |
| `main` | Main menu | `example:open` | `reply` from `/start`, `render` from back buttons | `MainMenuHandler` |

Action id style:

```text
domain:verb
domain:verb:detail
campaign:create
campaign:view
campaign:report:download
```

Use payloads for dynamic ids instead of embedding unbounded data into action ids.

## 6. Action Catalog

| Action Id | Payload | Source Screen | Handler | Ack | Result |
| --- | --- | --- | --- | --- | --- |
| `example:open` | `null` | `main` | `onOpenExample` | none | Render example screen |
| `campaign:view` | `{ "id": 123 }` | campaigns list | `onViewCampaign` | none | Render campaign details |

Rules:

- First registered route wins.
- Use exact actions for fixed behavior.
- Use action prefixes for grouped behavior.
- Use scene actions only for methods that belong to an active scene.
- Use URL actions only for buttons that should not call the bot.

## 7. Commands And Text Routes

| Input | Handler | Result |
| --- | --- | --- |
| `/start` |  |  |
| `/help` |  |  |
| text prefix |  |  |
| fallback |  |  |

Fallback policy:

```md
When no route matches:
When input arrives during a scene:
When text is invalid:
```

## 8. Scenes And Dialogs

For every dialog, define the scene and every step.

```md
Scene:
Entry action:
Exit behavior:
Back/cancel behavior:
Session keys:
```

| Step | Prompt | Expected Input | Validation | Error Message | Stores | Next |
| --- | --- | --- | --- | --- | --- | --- |
| `name` |  | text | required, unique |  | `draft.name` | `confirm` |

Scene actions:

| Action | Method | Payload | Result |
| --- | --- | --- | --- |
| Confirm | `onConfirm` |  |  |
| Cancel | `onCancel` |  |  |

## 9. Telegram-Specific Features

Mark every Telegram-only feature explicitly.

| Feature | Required | ChatFlow Tool | Notes |
| --- | --- | --- | --- |
| Force reply | no | `TelegramContext::forceReply()` |  |
| File download | no | `Context::downloadAttachment()` |  |
| Media groups | no | `TelegramMediaGroupCollector` |  |
| Membership updates | no | `onTelegramEvent('my_chat_member')` |  |
| Cross-chat publishing | no | `TelegramPublisher` |  |
| Screen cleanup | no | `TelegramScreenManager` |  |

If raw `Telegram\Bot\Api` is required, explain why the typed ChatFlow layer is not enough.

## 10. Integrations

| Integration | Purpose | Success | Failure | Retry |
| --- | --- | --- | --- | --- |
| External API |  |  |  |  |
| Database |  |  |  |  |
| Scheduler |  |  |  |  |

## 11. Error Policy

| Error | User Message | Ack/Error Alert | Log Level | Recovery |
| --- | --- | --- | --- | --- |
| Validation failed |  | yes/no | info | Ask again |
| Service unavailable |  | yes/no | error | Retry later |

Typed exceptions:

| Exception | Handler | User Result |
| --- | --- | --- |
| `ExampleNotFoundException` |  |  |

## 12. Observability

```md
Runtime observer:
Business logs:
Admin diagnostics:
Metrics:
Sensitive data that must not be logged:
```

## 13. Acceptance Scenarios

Write scenarios as user transcripts. Each scenario should map to one or more `TelegramBotTester` tests.

```md
Scenario: Create example successfully
Given user is allowed
When user sends /start
Then bot shows Main menu
When user clicks example:open
Then bot renders Example screen
```

Minimum scenario set:

- `/start` shows the main screen.
- Main navigation works.
- Each scene happy path works.
- Each scene validation error works.
- Permission failure works.
- Main integration failure works.
- Fallback behavior works.

## 14. Implementation Blueprint

Link to the implementation blueprint:

```md
Implementation blueprint: IMPLEMENTATION_BLUEPRINT.md
```

## 15. Open Questions

| Question | Owner | Required Before Coding |
| --- | --- | --- |
|  |  | yes/no |
