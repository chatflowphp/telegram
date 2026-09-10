# Scenes And Dialogs

Scenes are the states of a chat. The screen the user sees or the question the bot is waiting on
is the current scene; pressing buttons, answering and sending commands moves the conversation
along declared transitions. The full model is documented in the core package
(`docs/scenes.md`, `docs/transitions.md`); this page shows the Telegram usage.

## Register

```php
$bot->registerScene(CheckoutScene::class, 'Checkout');
$bot->allowTransition(RootScene::ID, ShopScene::class);
$bot->allowTransition(ShopScene::class, CheckoutScene::class, static fn (SceneContext $s): bool => $s->getArray('cart') !== []);
```

Transitions are optional; without them every scene can enter every other scene.

## Enter

```php
$bot->onAction('checkout:start', static function (Context $ctx): void {
    $ctx->ack('Starting checkout');
    $ctx->enter(CheckoutScene::class);
});
```

## A Scene

```php
use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;

final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $ctx->ask('Send phone in +79991234567 format')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->onText('cancel', 'onCancel')
            ->handle('savePhone');
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('phone', $ctx->getText());
        $ctx->reply('Phone saved');
        $ctx->leave();
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->back();
    }
}
```

Scenes are stateless services shared by every chat; keep data in `$ctx->session()`.

## Scene Actions

```php
$ctx->render(
    View::text('Cart')->addActionRow($this->sceneAction('Checkout', 'onCheckout', ['step' => 1])),
);

public function onCheckout(Context $ctx, int $step): void { ... }
```

Scene action buttons carry `scene:onMethod` callback data (signed when a payload is present) and
call the public method of the active scene.

## Commands Inside Scenes

Commands are global routes: `/start` while a scene is active runs the `/start` route. Use it for
`/cancel`, `/help` and `/start` resets. A scene that must consume everything overrides
`allowsGlobalRoutes()` to return `false`.

## Navigation

```php
$ctx->enter(NextScene::class, ['order_id' => 42], 'Cart');
$ctx->back();
$ctx->leave();
$ctx->canEnter(NextScene::class);
```

`enter()` pushes the current scene to history, `back()` returns to it (or to the root scene),
`leave()` ends the flow and clears history. Everything happens inside the current update; if a
hook throws, the chat stays where it was and nothing is sent except the error handler's message.

## Media Inside Scenes

Attachments sent while a scene is active go to the scene: an `onMedia()` shortcut of the pending
interaction, or `handle()`. `Bot::onMedia()` handlers run only outside scenes.
