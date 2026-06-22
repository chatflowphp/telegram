# Telegram Context

`TelegramContext` is a thin Telegram-specific wrapper around core `Context`.

Use it only when a handler needs Telegram-specific options or metadata.

## Injection

`TelegramPlatformAdapter` binds `TelegramContext` into the container for each handled update:

```php
$bot->command('debug', static function (TelegramContext $telegram): void {
    $telegram->reply('chat id: ' . $telegram->getChatId());
});
```

You can also request both contexts:

```php
$bot->command('debug', static function (Context $ctx, TelegramContext $telegram): void {
    $ctx->reply('conversation: ' . $ctx->getConversationId());
});
```

## Methods

```php
$telegram->core();
$telegram->reply($viewOrString, $options = null);
$telegram->render($viewOrString, $options = null);
$telegram->forceReply($viewOrString, $replyToMessageId = null, $options = null);
$telegram->getChatId();
$telegram->getMessageId();
$telegram->getCallbackQueryId();
$telegram->getRawUpdate();
```

## Reply And Render With Options

```php
$telegram->reply(
    '<b>Protected HTML message</b>',
    TelegramMessageOptions::html()->withProtectContent(true)
);
```

`reply()` and `render()` still enqueue core effects. They do not call Telegram immediately.

## Force Reply

```php
$telegram->forceReply('Send the changed post text');
```

Force reply uses the current message id when it is available.

## Current Message Helpers

`getChatId()` returns Telegram chat id when available and falls back to core conversation id.

`getMessageId()` returns current Telegram message id for message/callback updates.

`getCallbackQueryId()` returns callback query id when available.

`getRawUpdate()` returns the raw Telegram update array stored in inbound metadata.

## Escape Hatch

For unsupported Bot API endpoints, inject `Telegram\Bot\Api` directly:

```php
$bot->command('raw', static function (Api $api, TelegramContext $telegram): void {
    $api->sendChatAction([
        'chat_id' => $telegram->getChatId(),
        'action' => 'typing',
    ]);
});
```

Keep raw SDK calls isolated. Normal conversational output should use `Context` or `TelegramContext`.
