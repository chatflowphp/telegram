# Callbacks And Actions

Telegram callback queries are normalized into core action events.

## Creating Inline Buttons

Use core `Action` objects:

```php
use ChatFlow\View\Action;
use ChatFlow\View\View;

$ctx->reply(
    View::text('Open the menu')
        ->addActionRow(new Action('menu:open', 'Open menu'))
);
```

Telegram renders these as inline keyboard buttons.

## Handling Actions

Use exact, prefix or regex routes:

```php
$bot->onAction('menu:open', static function (Context $ctx): void {
    $ctx->ack();
    $ctx->render('Menu');
});

$bot->onActionPrefix('product:', $handler);
$bot->onActionRegex('/^cart:/', $handler);
```

Telegram-friendly `prefix()` maps to `onActionPrefix()`:

```php
$bot->prefix('menu:', $handler);
```

## Payloads

Actions can carry payload:

```php
new Action('cart:add', 'Add to cart', ['id' => 10])
```

The adapter encodes callback data and decodes it back to:

```php
$ctx->getActionId();
$ctx->getActionPayload();
```

## Acknowledgement

Use `ack()` for callback feedback:

```php
$ctx->ack('Saved');
$ctx->ack('Validation failed', true);
```

For Telegram callback queries, `ack()` calls `answerCallbackQuery()`. If there is no callback query and text is provided, Telegram sends a normal message.

## URL Buttons

An action with URL becomes a Telegram URL inline button:

```php
new Action('docs', 'Open docs', url: 'https://example.com')
```

URL buttons do not send callback queries.
