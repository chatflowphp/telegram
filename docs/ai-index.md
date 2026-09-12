# AI Index: ChatFlow Telegram

Compact context for implementing Telegram bots with ChatFlow 2.x.

## Model

- A chat is a state machine. Scenes are states; the root scene runs routes.
- One update is one tick. `enter()`, `back()`, `leave()` are transitions inside it.
- A failing handler rolls back the scene, the session and every queued message.
- Commands are global and interrupt scenes; other routes run in the root scene unless `->global()`.
- Callback payloads are signed; the tester signs them the same way.

## Development Process

For a production bot prepare `BOT_SPEC.md` (`docs/development/bot-spec-template.md`),
`IMPLEMENTATION_BLUEPRINT.md` and `TelegramBotTester` acceptance scenarios before coding.
Declare the screen map with `allowTransition()`.

## Basic Bot

```php
$bot = new Bot($token, $basePath, storage: new FileStorage($basePath . '/storage/bot'));

$bot->command('start', static function (Context $ctx): void {
    $ctx->reply(View::text('Welcome')->addActionRow(new Action('shop:open', 'Shop')));
});

$bot->onAction('shop:open', static function (Context $ctx): void {
    $ctx->ack();
    $ctx->enter(ShopScene::class);
});
```

## Routes

- Commands: `$bot->command('start', $handler)` (global).
- Text: `onTextPrefix()`, `onTextRegex()`, `fallback()`.
- Actions: `onAction()`, `onActionPrefix()` / `prefix()`, `onActionRegex()`.
- Media outside scenes: `onMedia('photo', $handler)`, `onMedia('any', $handler)`.
- Membership updates: `onTelegramEvent('my_chat_member', $handler)`; any other type is rejected.
- Updates without a chat (payments, inline queries): `onRawUpdate('pre_checkout_query', $handler)`,
  handled outside the conversation with `Telegram\Bot\Api` injected.
- Deep links: `$ctx->getCommandArgument()` for "/start ref_abc123".
- Long output is split automatically in replies; `TelegramPublisher` raises instead of splitting.
- 429 is handled by `TelegramRateLimiter`: short waits are retried, longer ones raise
  `TelegramRateLimitException` with `retry_after`.
- Groups: one conversation per chat by default, per member with
  `conversationScope: ConversationScope::ChatAndUser`.
- Localization: register `ChatFlow\I18n\TranslatorInterface`, add `LocaleMiddleware::class`,
  translate with `$ctx->t()`.

First registered route wins.

## Output

- New message: `$ctx->reply($viewOrString)`.
- Update current screen: `$ctx->render($view)`.
- Callback feedback: `$ctx->ack($text = null, $error = false)`.
- Inline buttons: `View::text(...)->addActionRow(new Action('id', 'Label', $payload))`.
- Reply keyboard: `->addChoiceRow(new Choice('Label', 'Value'))`.
- Media: `->addMedia(new MediaAttachment('image', $urlOrPath))`.

## Scenes

```php
$bot->registerScene(PhoneScene::class);
$bot->allowTransition(RootScene::ID, PhoneScene::class);
$ctx->enter(PhoneScene::class);

final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $ctx->ask('Question')
            ->validate('regex:/^(yes|no)$/', 'Answer yes or no.')
            ->onText('cancel', 'onCancel')
            ->handle('save');
    }

    public function save(Context $ctx): void
    {
        $ctx->session()->set('answer', $ctx->getText());
        $ctx->leave();
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->back();
    }
}
```

Scenes are stateless; data lives in `$ctx->session()`. Scene buttons:
`$this->sceneAction('Label', 'onMethod', $payload)`.

## Telegram-Specific Tools

- `TelegramContext`: force reply, message options, chat/message ids, raw update.
- `TelegramMessageOptions`, `TelegramView`: Bot API options on a core view.
- `TelegramPublisher`, `TelegramMedia`, `TelegramMediaSource`: immediate sends with message ids.
- `TelegramScreenManager`: track and clear published message groups.

## Testing

```php
$tester = new TelegramBotTester($bot, $mockClient);
$tester->sendCommand('start')->assertSee('Welcome')->clickButton('shop:open')->assertScene(ShopScene::class);
```

```sh
php examples/MiniShop/mock.php
```

## AI Implementation Prompt

```md
Implement this Telegram bot using chatflowphp/telegram 2.x.
Use ChatFlow\Core\Context, View and Action by default; TelegramContext only for Telegram-only behaviour.
Model screens and dialog steps as scenes; declare the screen map with allowTransition().
Start with TelegramBotTester acceptance tests from the scenarios; no real network calls in tests.
```

## Do Not

- Do not put Telegram SDK imports into the core repository.
- Do not use raw `Telegram\Bot\Api` for normal replies.
- Do not keep per-user state in scene properties.
- Do not store objects or resources in session or action payloads.
- Do not assume `render()` always edits; Telegram may force a fallback to send.
