# AI Index: ChatFlow Telegram

Use this file as compact context when asking AI to implement Telegram bots with ChatFlow.

## Active Scope

Active packages:

- `chatflowphp/core`
- `chatflowphp/telegram`

Research adapters are not active package targets.

## Development Process

For a new production bot, prepare these artifacts before implementation:

- `BOT_SPEC.md` based on `docs/development/bot-spec-template.md`.
- `IMPLEMENTATION_BLUEPRINT.md` based on `docs/development/implementation-blueprint-template.md`.
- `TelegramBotTester` acceptance scenarios based on `docs/development/acceptance-testing-guide.md`.

Use `docs/development/index.md` as the workflow entrypoint and `docs/development/spec-review-checklist.md` before coding.

The `docs/development/monitoring-bot-spec-example.md` file is a documentation-only example of a complex production bot spec.

## Default Implementation Rule

Use `ChatFlow\Core\Context` and core `View` objects for normal bot logic.

Use Telegram-specific classes only for Telegram-only behavior.

## Basic Bot

```php
$bot = new Bot($token, $basePath);
$bot->useStorage(new FileStorage($basePath . '/storage/bot'));

$bot->command('start', static function (Context $ctx): void {
    $ctx->reply('Welcome');
});
```

## Routes

- Commands: `$bot->command('start', $handler)` or `$bot->onCommand('start', $handler)`.
- Text prefix: `$bot->onTextPrefix('/search', $handler)`.
- Text regex: `$bot->onTextRegex('/^...$/', $handler)`.
- Action exact: `$bot->onAction('id', $handler)`.
- Action prefix: `$bot->prefix('cart:', $handler)` or `$bot->onActionPrefix('cart:', $handler)`.
- Fallback: `$bot->fallback($handler)`.

First registered route wins.

## Output

- New message: `$ctx->reply($viewOrString)`.
- Update current screen: `$ctx->render($view)`.
- Callback feedback: `$ctx->ack($text = null, $error = false)`.
- Inline buttons: `View::text(...)->addActionRow(new Action('id', 'Label', $payload))`.
- Reply keyboard: `View::text(...)->addChoiceRow(new Choice('Label', 'Value'))`.
- Media: `View::text(...)->addMedia(new MediaAttachment('image', $urlOrPath))`.

## Scenes

```php
$bot->registerScene(MyScene::class);
$ctx->enter(MyScene::class);
```

Scene pattern:

```php
final class MyScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $this->ask('Question')
            ->validate('regex:/^yes|no$/', 'Answer yes or no.')
            ->handle([$this, 'save']);
    }
}
```

## Telegram-Specific Tools

- `TelegramContext`: force reply and current Telegram metadata.
- `TelegramMessageOptions`: parse mode, reply-to, force reply, protect content, extra params.
- `TelegramView`: attach Telegram options to a core view.
- `TelegramPublisher`: immediate send/edit/delete with Telegram delivery results.
- `TelegramMedia` and `TelegramMediaSource`: typed publisher media.
- `TelegramScreenManager`: track publisher delivery results and clear message groups.
- `onTelegramEvent('my_chat_member', $handler)`: membership updates.
- `onMedia('photo', $handler)` or `onMedia('any', $handler)`: media outside active scenes.

## Testing

Use `TelegramBotTester`:

```php
$tester
    ->sendCommand('start')
    ->assertSee('Welcome')
    ->clickButton('menu:open')
    ->assertEndpointCalled('answerCallbackQuery');
```

Run the canonical example:

```sh
php examples/MiniShop/mock.php
```

## AI Implementation Prompt

```md
Implement this Telegram bot using chatflowphp/telegram.
Use ChatFlow\Core\Context, View and Action by default.
Use TelegramContext only for Telegram-specific behavior.
Start by adding TelegramBotTester acceptance tests from the scenarios.
Do not use real Telegram network calls in tests.
```

## Do Not

- Do not put Telegram SDK imports into the core repository.
- Do not use raw `Telegram\Bot\Api` for normal replies.
- Do not use `TelegramPublisher` for ordinary conversational output.
- Do not store arbitrary objects/resources in session or action payload.
- Do not assume `render()` always edits; Telegram may force fallback to send.
