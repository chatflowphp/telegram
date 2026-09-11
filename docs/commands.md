# Commands And Text Routes

Telegram text messages are normalized into core text events.

## Commands

```php
$bot->command('start', static function (Context $ctx): void {
    $ctx->reply('Welcome');
});
```

`command()` is sugar over `onCommand()`. A command route matches `/start`, `/start argument text`
and the group form `/start@your_bot`.

Commands are **global**: they run even while a scene is active, so `/start`, `/cancel` and
`/help` always work. A scene can refuse them by returning `false` from `allowsGlobalRoutes()`.
Turn a command into a root-only route with `->global(false)`.

## Deep Links And Arguments

`$ctx->getCommandArgument()` returns the text after the command, which is what a deep link
(`https://t.me/your_bot?start=ref_abc123`) delivers as `/start ref_abc123`:

```php
$bot->command('start', static function (Context $ctx, ReferralService $referrals): void {
    $referral = $ctx->getCommandArgument();

    if ($referral !== '') {
        $referrals->attach($ctx->getUserId(), $referral);
    }

    $ctx->reply('Welcome');
});
```

The group form is handled as well: `/start@your_bot ref_abc123` yields the same argument.

## Core Route API

```php
$bot->onTextPrefix('/search', $handler);
$bot->onTextRegex('/^order:\d+$/', $handler);
$bot->onCommand('help', $handler);
$bot->fallback($handler);
```

First registered route wins. Text prefix, regex and fallback routes run only in the root scene
unless marked `->global()`.

## Handler Dependencies

Handlers are called through the container. Parameters resolve by type hint or by name:

```php
$bot->getContainer()->set(OrderService::class, new OrderService());

$bot->command('orders', static function (Context $ctx, OrderService $orders): void {
    $ctx->reply($orders->summaryFor((string) $ctx->getUserId()));
});
```

## Portable Code

Use `ChatFlow\Core\Context` for business handlers. Use `TelegramContext` only when the handler
needs Telegram-specific data or behaviour.
