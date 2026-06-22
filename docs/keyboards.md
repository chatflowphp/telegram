# Keyboards

ChatFlow uses platform-neutral `Action` and `Choice` objects.

## Inline Callback Keyboard

Actions become Telegram inline keyboard buttons:

```php
View::text('Product')
    ->addActionRow(
        new Action('cart:add', 'Add', ['id' => 10]),
        new Action('cart:open', 'Cart')
    );
```

Telegram request shape:

```json
{"inline_keyboard":[[{"text":"Add","callback_data":"..."}]]}
```

## Reply Keyboard Choices

Choices become Telegram reply keyboard buttons:

```php
View::text('Choose delivery')
    ->addChoiceRow(
        new Choice('Courier', 'Courier'),
        new Choice('Pickup', 'Pickup')
    );
```

Telegram request shape:

```json
{"keyboard":[[{"text":"Courier"}]],"resize_keyboard":true,"one_time_keyboard":true}
```

## URL Buttons

Use an `Action` with `url`:

```php
new Action('open-site', 'Open site', url: 'https://example.com')
```

Telegram renders it as a URL inline button and no action route is triggered.

## Callback Size Limit

Telegram limits callback data to 64 bytes. `chatflowphp/telegram` automatically stores long action payloads behind short `cf:<token>` callback data and restores the original payload on inbound callback.
