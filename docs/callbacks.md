# Callbacks And Actions

Telegram callback queries are normalized into core action events.

## Creating Inline Buttons

```php
use ChatFlow\View\Action;
use ChatFlow\View\View;

$ctx->reply(
    View::text('Open the menu')
        ->addActionRow(new Action('menu:open', 'Open menu'))
        ->addActionRow(new Action('cart:add', 'Add to cart', ['id' => 10])),
);
```

Actions become inline keyboard buttons. Payloads are signed (or stored behind a token when they
do not fit into 64 bytes); see [Callback Payload Store](callback-payload-store.md).

## Handling Actions

```php
$bot->onAction('menu:open', static function (Context $ctx): void {
    $ctx->ack();
    $ctx->render('Menu');
});

$bot->onActionPrefix('product:', $handler);
$bot->onActionRegex('/^cart:/', $handler);
$bot->prefix('menu:', $handler);       // sugar for onActionPrefix()
```

Action routes run in the root scene. Mark one `->global()` to make it work inside scenes as well,
for example a "Home" button present on every screen.

## Payloads

```php
$ctx->getActionId();       // 'cart:add'
$ctx->getActionPayload();  // ['id' => 10]
```

Callback data that was not produced by the bot (unsigned or tampered) is rejected before any
handler runs; the update returns `Result::noMatch('unsupported_update')`.

## Acknowledgement

```php
$ctx->ack();
$ctx->ack('Saved');
$ctx->ack('Validation failed', true);   // alert
```

For callback queries `ack()` calls `answerCallbackQuery()`. Without a callback query and with
text, it sends a message.

Every callback query gets answered: when no handler called `ack()`, the adapter answers it
silently after the update was handled, so the button never keeps spinning. When a handler
fails, the default error handler answers with an alert.

## URL Buttons

```php
new Action('docs', 'Open docs', url: 'https://example.com')
```

URL buttons do not send callback queries.
