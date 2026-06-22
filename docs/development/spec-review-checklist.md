# Bot Spec Review Checklist

Use this checklist before implementation starts. The goal is to catch vague requirements while they are still cheap to fix.

## Product And Scope

- [ ] The bot goal is measurable.
- [ ] Non-goals are explicit.
- [ ] User roles are defined.
- [ ] Permission failures are user-facing.
- [ ] The spec states what is out of scope for the first release.

## Telegram UX

- [ ] `/start` behavior is defined.
- [ ] Main menu text and buttons are defined.
- [ ] Every screen has a stable screen id.
- [ ] Every screen says whether it uses `reply()` or `render()`.
- [ ] Back/cancel behavior is defined.
- [ ] Fallback behavior is defined.
- [ ] Callback feedback via `ack()` is defined where needed.

## Actions

- [ ] Every inline button has an action id.
- [ ] Action ids use `domain:verb` style.
- [ ] Dynamic data is in payloads, not unbounded action ids.
- [ ] Payloads are serializable.
- [ ] URL buttons are marked as URL buttons.
- [ ] Broad prefix routes cannot shadow specific routes accidentally.

## Scenes And Dialogs

- [ ] Every scene has an entry action.
- [ ] Every step has prompt text.
- [ ] Every step has validation rules.
- [ ] Every validation error has exact user text.
- [ ] Session keys are listed.
- [ ] Scene completion and cleanup behavior are defined.
- [ ] Cancel/back behavior is defined.

## Data And Integrations

- [ ] Persistent entities are listed.
- [ ] Required fields are listed.
- [ ] External APIs are listed.
- [ ] External API failure behavior is defined.
- [ ] Scheduler/queue requirements are defined if needed.
- [ ] Report/export formats are defined if needed.

## Telegram-Specific Features

- [ ] Force reply requirements are explicit.
- [ ] File download requirements are explicit.
- [ ] Media group behavior is explicit.
- [ ] Membership event requirements are explicit.
- [ ] Cross-chat publishing requirements are explicit.
- [ ] Required Telegram bot permissions are listed.
- [ ] Any raw Telegram SDK usage has a reason.

## Testing

- [ ] Every main journey has an acceptance scenario.
- [ ] Validation failures have acceptance scenarios.
- [ ] Permission failures have acceptance scenarios.
- [ ] External failure behavior has at least one scenario.
- [ ] Expected user-facing text is precise enough to assert.
- [ ] Expected Telegram endpoints are listed when endpoint choice matters.

## Implementation Readiness

- [ ] `IMPLEMENTATION_BLUEPRINT.md` exists.
- [ ] Class names for flows/scenes/services are chosen.
- [ ] Storage strategy is chosen.
- [ ] Test fakes are planned.
- [ ] Environment variables are listed.
- [ ] Open questions are either resolved or explicitly deferred.
