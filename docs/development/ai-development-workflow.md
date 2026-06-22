# AI Development Workflow

This guide explains how to use an AI coding agent to implement Telegram bots with `chatflowphp/telegram`.

## Context Bundle

Give the agent these files first:

- `docs/ai-index.md`
- `docs/BOT_SPEC.md` from the bot project
- `docs/IMPLEMENTATION_BLUEPRINT.md` from the bot project
- Existing example or mock runner, if any

If the project has no spec yet, ask the agent to create or refine `BOT_SPEC.md` before writing PHP code.

## Recommended Prompt

```md
Implement this Telegram bot using chatflowphp/telegram.

Use ChatFlow\Core\Context, View and Action by default.
Use TelegramContext only for Telegram-specific behavior.
Use TelegramPublisher only when immediate Telegram delivery results are required.
Start by adding TelegramBotTester acceptance tests from the scenarios.
Do not use real Telegram network calls in tests.
Do not introduce runtime API changes unless the spec explicitly requires them.

Inputs:
- docs/BOT_SPEC.md
- docs/IMPLEMENTATION_BLUEPRINT.md
- vendor docs/ai-index.md
```

## Plan-First Rule

For non-trivial bots, ask the agent for a plan before implementation:

```md
Read the spec and blueprint.
Inspect the current project.
Return a concrete implementation plan with files, classes, test scenarios and assumptions.
Do not edit files yet.
```

Only switch to implementation after the plan is decision-complete.

## Implementation Rules For Agents

Agents should:

- Inspect existing code before editing.
- Preserve core/Telegram boundaries.
- Add tests before or alongside implementation.
- Prefer `TelegramBotTester` over manual request arrays.
- Keep route handlers small and move business logic to services.
- Keep Telegram SDK usage inside Telegram-specific infrastructure.
- Use serializable values for session and action payloads.
- Run the full quality gate before finishing.

Agents should not:

- Use raw `Telegram\Bot\Api` for normal replies, renders or callback acks.
- Store objects, closures or resources in session.
- Hide Telegram API failures in tests.
- Assume `render()` always edits the message.
- Add compatibility aliases for old package names.
- Change `chatflowphp/core` for Telegram-only behavior.

## Turning Scenarios Into Tests

Spec scenario:

```md
Scenario: Create campaign successfully
Given user is an admin
When user sends /start
Then bot shows Main menu
When user clicks campaign:create
Then bot asks for campaign name
```

Test skeleton:

```php
$tester
    ->sendCommand('start')
    ->assertSee('Main menu')
    ->assertKeyboardHas('Create campaign')
    ->clear()
    ->clickButton('campaign:create')
    ->assertSee('Enter campaign name');
```

## Review Checklist For AI Output

Before accepting generated code, verify:

- All action ids match the spec.
- All scene prompts and validation messages match the spec.
- All Telegram-only behavior is isolated.
- Acceptance tests do not use real network calls.
- The mock runner exercises the main path.
- `composer validate --strict`, `composer check` and `composer cs-check` pass.

## Useful Compact Context

When token budget is limited, send this summary:

```md
Use chatflowphp/telegram.
Normal bot logic uses Context, View, Action, scenes and sessions.
Telegram-specific behavior uses TelegramContext, TelegramView, TelegramPublisher, media group collector and Telegram events.
Routes: command(), onCommand(), onAction(), prefix(), onActionPrefix(), onTextPrefix(), onTextRegex(), fallback().
Testing: TelegramBotTester + MockHttpClient; no real Telegram calls.
Quality gate: composer validate --strict && composer check && composer cs-check.
```
