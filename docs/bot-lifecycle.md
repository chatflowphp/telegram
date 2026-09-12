# Bot Lifecycle

`ChatFlow\Telegram\Bot` is the Telegram-facing runtime facade. It owns the SDK `Api`, the core
`Application`, the router, the container, the scene registry and transitions, the validation
registry, the platform adapter, the publisher, the callback payload encoder and the media group
collector.

## Construction

```php
$bot = new Bot(
    token: $_ENV['TELEGRAM_BOT_TOKEN'],
    basePath: __DIR__,
    storage: new FileStorage(__DIR__ . '/storage/bot'),
    sessionTtlSeconds: 86400,
    webhookSecret: $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? null,
);
```

Optional constructor arguments: `logger`, `container`, `webhookSecret`, `api`, `errorHandler`,
`router`, `sceneRegistry`, `validationRegistry`, `runtimeObserver`, `callbackPayloadEncoder`,
`mediaGroupCollector`, `publisher`, `storage`, `sessionTtlSeconds`, `debug`, `callbackSecret`,
`conversationScope`, `rateLimiter`.

`conversationScope` decides what a conversation is in a group chat; see
[Group Chats](groups.md).

`basePath` is where the default file stores live: `storage/telegram-callbacks` and
`storage/telegram-media-groups`. Directories are created on first use.

## Configuration Phase

Routes, scenes, transitions, middleware and error handlers are registered on the bot. The core
`Application` is built on the first handled update; `useStorage()` must be called before that.

```php
$bot->useStorage(new RedisStorage($redis), sessionTtlSeconds: 86400);
$bot->registerScene(CheckoutScene::class);
$bot->allowTransition(RootScene::ID, CheckoutScene::class);
$bot->middleware([TypingMiddleware::class]);
```

## Handling Updates

`handle()` accepts a Telegram SDK `Update` or a raw update array:

1. An update with an `onRawUpdate()` handler is dispatched outside the conversation runtime and
   the result is returned; see [Payments And Updates Without A Chat](payments.md).
2. `my_chat_member` / `chat_member` updates with an `onTelegramEvent()` handler become a global
   custom route.
3. Album parts are collected by the media group collector; pending parts return
   `Result::noMatch('telegram_media_group_pending')`.
4. The update is normalized into a core inbound event. Updates without a chat and callback
   queries with forged data are skipped with `Result::noMatch('unsupported_update')`.
5. Messages with attachments matching an `onMedia()` handler get a custom route that runs only
   when no scene is active.
6. The core `Application` runs one tick: middleware, root route or scene, rollback on failure,
   persistence, delivery of queued effects.

## Webhook

```php
$result = $bot->runWebhook();
$result = $bot->runWebhook($request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token'));
```

With a configured `webhookSecret`, the token is compared in constant time; a mismatch throws
`ValidationException` before the update is read.

## Polling

```php
$bot->startPolling(timeout: 30, allowedUpdates: ['message', 'callback_query', 'my_chat_member']);
```

Polling deletes the webhook first, groups album parts that arrive in the same batch and handles
`SIGINT` / `SIGTERM` when `pcntl` is available.

## Acting From Outside A Request

Schedulers, admin panels and other chats move conversations without a Telegram update:

```php
$bot->enterScene($chatId, ReviewScene::class, ['campaign' => 42]);   // asks now
$bot->enterScene($chatId, ReviewScene::class, userId: $userId);      // one member of a group
$bot->leaveScene($chatId);
$bot->run($chatId, static function (Context $ctx): void {          // any handler, as a system tick
    $ctx->reply('Your report is ready');
});
$bot->getConversations()->enterLater($chatId, ReviewScene::class);   // starts on the next message
```

Immediate calls run a system tick through the regular runtime and send the scene's messages to
the chat. `enterLater()` records the intent; the scene is entered, with its `onEnter()` output,
when the chat's next update arrives, and that update is consumed unless `handleTrigger: true`.

## Timers

Give the bot a timer store to let scenes and handlers wake a chat later (`$ctx->wakeAt()`),
and a clock when the time should come from somewhere else than the system:

```php
$bot = new Bot($token, __DIR__, storage: $storage, timers: new FileTimerStore(__DIR__ . '/storage'));
```

Timers are delivered by `runDue()`; call it from a scheduler as often as the shortest timer you
use, together with `drain()` for the side effects a crashed request left behind:

```php
$application = $bot->getApplication();
$application->runDue();
```

Without a store `wakeAt()` schedules nothing. See `chatflowphp/core` `docs/timers.md`.

## Runtime Access

```php
$bot->getApi();
$bot->getApplication();
$bot->getConversations();   // inspect or reset conversations
$bot->getContainer();
$bot->getScenes();
$bot->getTransitions();     // toMermaid() for the screen map
$bot->getPublisher();
$bot->getCallbackEncoder();
```

## Error Policy

```php
$bot->onException(ProductNotFoundException::class, static function (Throwable $e, ?Context $ctx): void {
    $ctx?->ack('Product is not available.', true);
});

$bot->setErrorHandler(static function (Throwable $e, Context $ctx): void {
    $ctx->reply('Unexpected error. Type /start to retry.');
});
```

The most specific handler wins. Telegram "message is not modified", "query is too old" and
forbidden errors are ignored by default. When a handler or scene fails, the conversation rolls
back and only the error handler replies.
