# Keyboards

ChatFlow uses platform-neutral `Action` and `Choice` objects.

## Inline Callback Keyboard

```php
View::text('Product')
    ->addActionRow(new Action('cart:add', 'Add', ['id' => 10]), new Action('cart:open', 'Cart'));
```

Telegram request shape:

```json
{"inline_keyboard":[[{"text":"Add","callback_data":"cs:..."},{"text":"Cart","callback_data":"cart:open"}]]}
```

## Reply Keyboard Choices

```php
View::text('Choose delivery')
    ->addChoiceRow(new Choice('Courier', 'Courier'), new Choice('Pickup', 'Pickup'));
```

Telegram request shape:

```json
{"keyboard":[[{"text":"Courier"},{"text":"Pickup"}]],"resize_keyboard":true,"one_time_keyboard":true}
```

Pressing a choice sends its value as a text message.

## URL Buttons

```php
new Action('open-site', 'Open site', url: 'https://example.com')
```

## Callback Size Limit

Telegram limits callback data to 64 bytes. Action ids without payload are sent as-is, small
payloads are signed inline, larger payloads are stored behind a `cf:<token>`. See
[Callback Payload Store](callback-payload-store.md).
