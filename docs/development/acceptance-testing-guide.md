# Acceptance Testing Guide

Acceptance tests prove that the bot behaves like the specification says, without calling Telegram.

Use:

- `ChatFlow\Telegram\Testing\TelegramBotTester`
- `ChatFlow\Telegram\Testing\MockHttpClient`
- memory or temporary storage
- fake business services

## Test Shape

A good acceptance test follows the user transcript:

```php
$tester
    ->sendCommand('start')
    ->assertSee('Main menu')
    ->assertKeyboardHas('Create campaign')
    ->clear()
    ->clickButton('campaign:create')
    ->assertSee('Enter campaign name');
```

Assert user-visible behavior first. Assert Telegram endpoint details only when the endpoint is part of the requirement.

## Recommended Scenario Set

Every production bot should have tests for:

- `/start` and main menu.
- Main navigation path.
- Each scene happy path.
- Each scene validation error.
- Permission denial.
- Fallback input.
- Important Telegram-specific behavior.
- Main external integration failure.

## Mapping Spec To Tests

Spec:

```md
Scenario: Duplicate campaign name
Given user has campaign "June Promo"
When user starts campaign creation
And enters "June Promo"
Then bot says "This campaign name is already used."
And user stays in CreateCampaignScene
```

Test:

```php
$tester
    ->sendCommand('start')
    ->clear()
    ->clickButton('campaign:create')
    ->clear()
    ->sendMessage('June Promo')
    ->assertSee('This campaign name is already used.')
    ->assertScene(CreateCampaignScene::class);
```

## Assertions To Prefer

Use these for conversational behavior:

```php
$tester->assertSee('Text');
$tester->assertKeyboardHas('Button');
$tester->assertScene(MyScene::class);
$tester->assertNotInScene();
$tester->assertSessionHas('key', 'value');
```

Use endpoint assertions for Telegram-specific delivery:

```php
$tester->assertEndpointCalled('answerCallbackQuery');
$tester->assertEndpointCalled('sendPhoto');
$tester->assertEndpointCalled('editMessageText');
```

Inspect requests only when needed:

```php
$requests = $tester->getRequests();
```

## Testing Scenes

Scene tests should cover:

- Entry action.
- Prompt text.
- Invalid input.
- Valid input.
- Stored session values.
- Scene exit.
- Back/cancel actions where applicable.

Avoid testing private scene internals. Test the public transcript instead.

## Testing Telegram-Specific Features

| Feature | Test Strategy |
| --- | --- |
| Callback ack | Assert `answerCallbackQuery` or user-visible fallback |
| Media reply | Assert `sendPhoto`, `sendDocument` or relevant endpoint |
| Render/edit | Assert `editMessageText` only when edit behavior is required |
| File download | Use mock file responses and temporary directories |
| Media groups | Use `sendMediaGroup()` helper and assert one aggregated handling path |
| Membership events | Use `sendRawUpdate()` with `my_chat_member` or `chat_member` |
| Publishing | Test `TelegramPublisher` with `MockHttpClient` |

## Failure Tests

Failure tests are not optional for production bots.

Cover at least:

- Invalid user input.
- Missing permissions.
- Not found entity.
- External API failure.
- Telegram delivery failure if the feature depends on delivery.

User-facing messages should be precise. Logs should contain enough context for debugging, but tests should not assert secrets.

## Mock Runner

Every non-trivial bot should have a mock runner that executes the main scenario:

```sh
php examples/MyBot/mock.php
```

The runner should:

- Build the same bot factory as production.
- Use `MockHttpClient`.
- Use test storage.
- Print a short transcript or key outgoing Telegram requests.

## Quality Gate

Run:

```sh
composer validate --strict
composer check
composer cs-check
```

If the package contains an example bot, also run its mock script.
