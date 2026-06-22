# Telegram Bot Development Kit

This section is a workflow kit for designing and implementing production Telegram bots with `chatflowphp/telegram`.

If you are building your first bot, start with [Getting Started](../getting-started.md). This section is for production planning, specification and acceptance-driven implementation.

It is not an API reference. API behavior is documented in the runtime docs one level above this directory.

## What This Kit Produces

The intended output is a bot project with these artifacts:

| Step | Input | Output |
| --- | --- | --- |
| 1. Product specification | Product idea, business notes, user flow notes | `docs/BOT_SPEC.md` |
| 2. Spec review | `BOT_SPEC.md` | reviewed spec with resolved open questions |
| 3. Implementation blueprint | reviewed `BOT_SPEC.md` | `docs/IMPLEMENTATION_BLUEPRINT.md` |
| 4. Acceptance tests | spec scenarios and blueprint | `TelegramBotTester` tests |
| 5. Implementation | blueprint and tests | bot flow, scenes, services, views and mock runner |
| 6. Verification | implemented bot | green quality gate and runnable mock scenario |

## Recommended User Flow

### 1. Start With The Process

Read [Development Process](development-process.md).

Use it to decide whether the bot is ready for specification. Do not start by writing handlers unless the flow is trivial.

Expected output: clear agreement that the bot needs a spec, a blueprint and acceptance scenarios.

### 2. Write `BOT_SPEC.md`

Copy [Bot Spec Template](bot-spec-template.md) into the bot project as:

```text
docs/BOT_SPEC.md
```

Fill it from product requirements. The spec must define screens, actions, scenes, roles, entities, integrations and acceptance scenarios.

Expected output: a product-level contract that is precise enough to implement without inventing behavior.

### 3. Review The Spec

Use [Spec Review Checklist](spec-review-checklist.md).

Do not proceed to implementation while required questions are still open.

Expected output: a reviewed spec with stable user-facing text, action ids, validation rules and Telegram-specific requirements.

### 4. Write `IMPLEMENTATION_BLUEPRINT.md`

Copy [Implementation Blueprint Template](implementation-blueprint-template.md) into the bot project as:

```text
docs/IMPLEMENTATION_BLUEPRINT.md
```

Map product behavior to ChatFlow classes, routes, scenes, services, storage and tests.

Expected output: an engineering plan that leaves no class, route, scene or service decision to the implementer.

### 5. Convert Scenarios To Tests

Use [Acceptance Testing Guide](acceptance-testing-guide.md).

Write `TelegramBotTester` tests for the main user journeys before or alongside implementation.

Expected output: executable acceptance tests with `MockHttpClient` and no real Telegram calls.

### 6. Implement With AI Or Manually

If using an AI agent, use [AI Development Workflow](ai-development-workflow.md).

Give the agent:

- `docs/BOT_SPEC.md`
- `docs/IMPLEMENTATION_BLUEPRINT.md`
- `vendor/chatflowphp/telegram/docs/ai-index.md`

Expected output: flow registration, scenes, services, views, tests and mock runner that match the spec.

### 7. Verify

Run:

```sh
composer validate --strict
composer check
composer cs-check
```

Run the bot project's own mock scenario.

Expected output: green quality gate and a reproducible local transcript.

## Reference Example

Use [Monitoring Statistics Bot Spec Example](monitoring-bot-spec-example.md) as a production-level example of a complex bot spec.

It is intentionally a documentation-only example, not implemented code.

## When To Use Telegram-Specific APIs

Default to core ChatFlow primitives:

- `ChatFlow\Core\Context`
- `ChatFlow\View\View`
- `ChatFlow\View\Action`
- scenes, middleware and sessions

Use Telegram-specific tools only for Telegram-specific requirements:

- `TelegramContext` for force reply and current Telegram metadata.
- `TelegramView` and `TelegramMessageOptions` for Telegram Bot API options.
- `TelegramPublisher` for immediate cross-chat delivery results.
- media group collector for Telegram albums.
- `onTelegramEvent()` for membership updates.
- `TelegramScreenManager` for best-effort message group cleanup.

## About `SKILL.md`

A real Codex `SKILL.md` is useful when this workflow should become an installable local AI capability. It should live in a Codex skills directory, not as the primary public documentation for the package.

This package currently provides AI-ready public docs instead:

- [AI Development Workflow](ai-development-workflow.md)
- [AI Index](../ai-index.md)
- spec and blueprint templates

If this workflow becomes repetitive across projects, create a separate `chatflow-telegram-bot` Codex skill that points to these docs as references.
