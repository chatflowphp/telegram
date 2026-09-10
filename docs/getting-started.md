# Getting Started

Build your first Telegram bot with the minimal runnable example in `examples/StarterBot`.

If you are designing a production bot with formal specs and acceptance scenarios, start with the
[Telegram Bot Development Kit](development/index.md) instead.

## What You Will Build

- a `BotFactory` that creates the Telegram runtime with storage;
- one `/start` command and one `/cancel` command that works everywhere;
- one inline button that opens a settings screen with `ack()` and `render()`;
- one scene that validates a phone number and can be cancelled;
- one `TelegramBotTester` acceptance test;
- a local `mock.php` runner and a polling script.

Reference implementation:

- [StarterBotFactory.php](../examples/StarterBot/StarterBotFactory.php)
- [StarterBotFlow.php](../examples/StarterBot/StarterBotFlow.php)
- [PhoneScene.php](../examples/StarterBot/PhoneScene.php)
- [StarterBotFlowTest.php](../tests/Integration/StarterBotFlowTest.php)

## 1. Install

```sh
composer require chatflowphp/telegram
```

## 2. Create A Bot Factory

```php
final class StarterBotFactory
{
    public static function create(string $token, string $basePath): Bot
    {
        $bot = new Bot(
            token: $token,
            basePath: $basePath,
            storage: new FileStorage($basePath . '/storage/starter-bot'),
        );
        (new StarterBotFlow())->register($bot);

        return $bot;
    }
}
```

Use file storage locally and `MemoryStorage` in tests. Storage keeps the current scene and the
session data of every chat between updates.

## 3. Add `/start` And The First Screen

```php
$runtime->onCommand('start', static function (Context $ctx): void {
    $ctx->reply(View::text('Starter Bot')->addActionRow(new Action('settings:open', 'Open settings')));
});
```

Keep handlers on `Context`; reach for `TelegramContext` only for Telegram-only behaviour.

## 4. Handle A Callback With `ack()` And `render()`

```php
$runtime->onAction('settings:open', static function (Context $ctx): void {
    $ctx->ack('Opening settings');
    $ctx->render(StarterBotFlow::settingsView($ctx));
});
```

`reply()` for a new message, `render()` for the same logical screen, `ack()` for button feedback.

## 5. Add A Scene With Validation

Register the scene, declare where it can be entered from, and enter it from a callback:

```php
$runtime->registerScene(PhoneScene::class);
$runtime->allowTransition(RootScene::ID, PhoneScene::class);

$runtime->onAction('profile:phone', static function (Context $ctx): void {
    $ctx->ack('Updating phone');
    $ctx->enter(PhoneScene::class);
});
```

```php
final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $ctx->ask('Send your phone in +79991234567 format, or /cancel.')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->onText('cancel', 'onCancel')
            ->handle('savePhone');
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('profile.phone', $ctx->getText());
        $ctx->reply(StarterBotFlow::settingsView($ctx, 'Phone saved.'));
        $ctx->leave();
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->leave();
    }
}
```

While the scene is active, text goes through the validator, `cancel` calls `onCancel()`, and
commands such as `/cancel` or `/start` still run because commands are global.

## 6. Add An Acceptance Test

```php
$tester
    ->sendCommand('/start')
    ->assertSee('Starter Bot')
    ->clickButton('settings:open')
    ->assertEndpointCalled('editMessageText')
    ->clickButton('profile:phone')
    ->assertScene(PhoneScene::class)
    ->sendMessage('+79991234567')
    ->assertNotInScene()
    ->assertSessionHas('profile.phone', '+79991234567');
```

## 7. Run The Mock Scenario

```sh
php examples/StarterBot/mock.php
```

## 8. Run Polling Locally

```sh
TELEGRAM_BOT_TOKEN=... php examples/StarterBot/run.php
```

## Next Steps

- [Examples](examples.md) and `MiniShop` for a larger bot with a declared screen map.
- [Telegram Context](telegram-context.md) for force reply and update metadata.
- [Telegram Bot Development Kit](development/index.md) for production planning.
