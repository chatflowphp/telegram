# Scenes And Dialogs

Scenes are implemented in core with `ChatFlow\FSM\BaseScene` and work in Telegram through `Bot::registerScene()`.

If you are new to the package, complete [Getting Started](getting-started.md) first.

For Telegram bot authors, the important rule is simple: scene state is session-backed, scenes require storage, and you do not need lower-level FSM internals to build dialogs.

## Register Scenes

```php
$bot->useStorage(new FileStorage(__DIR__ . '/storage/bot'));
$bot->registerScene(CheckoutScene::class);
```

Storage is required because scene state is session-backed.

## Enter A Scene

```php
$bot->onAction('checkout:start', static function (Context $ctx): void {
    $ctx->ack('Starting checkout');
    $ctx->enter(CheckoutScene::class);
});
```

## Scene Example

```php
use ChatFlow\Core\Context;
use ChatFlow\FSM\BaseScene;

final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $this->ask('Send phone in +79991234567 format')
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->handle([$this, 'savePhone']);
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('phone', $ctx->getText());
        $ctx->reply('Phone saved');
        $this->leave();
    }
}
```

## Scene Actions

Scenes can render action buttons that call scene methods:

```php
$ctx->render(
    View::text('Cart')
        ->addActionRow($this->sceneAction('Checkout', 'onCheckout'))
);
```

The action id uses the `scene:` prefix internally.

## Validation

Validation can be attached to `ask()`:

```php
$this->ask('Enter email')
    ->validate('regex:/^[^@]+@[^@]+\.[^@]+$/', 'Invalid email.')
    ->handle([$this, 'saveEmail']);
```

Parameterized validation rules are resolved through the validation registry.

## Navigation

```php
$ctx->enter(NextScene::class);
$ctx->back();
$ctx->leave();
$ctx->clearHistory();
```

Use `clearHistory()` before ending a multi-step flow when the user should not return to previous scenes.
