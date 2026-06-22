# Force Reply

Force reply is Telegram-specific and is exposed through `TelegramContext` and `TelegramView`.

## With TelegramContext

```php
use ChatFlow\Telegram\TelegramContext;

$bot->command('ask', static function (TelegramContext $telegram): void {
    $telegram->forceReply('Send the new post text');
});
```

By default, `forceReply()` replies to the current Telegram message id when it is available.

## With TelegramView

```php
use ChatFlow\Telegram\TelegramView;

$ctx->reply(TelegramView::forceReply('Send details'));
```

## Options

```php
$telegram->forceReply(
    view: 'Send details',
    replyToMessageId: 123,
    options: TelegramMessageOptions::html()->withProtectContent(true)
);
```

Force reply is encoded as Telegram `reply_markup` with `force_reply: true`.

## When To Use

Use force reply for Telegram-native admin dialogs where replying to a specific message matters.

Use core scenes with `ask()` for portable multi-step dialogs.
