# Middleware

Core middleware works in Telegram through `Bot::middleware()`.

## Register Middleware

```php
$bot->middleware([
    MyMiddleware::class,
]);
```

Middleware receives core `Context`:

```php
final class VisitorMiddleware implements MiddlewareInterface
{
    public function process(Context $ctx, callable $next): mixed
    {
        $ctx->set('visitor', $ctx->getUserId() ?? $ctx->getConversationId());

        return $next($ctx);
    }
}
```

## Typing Middleware

`ChatFlow\Telegram\Middleware\TypingMiddleware` sends Telegram `typing` chat action before continuing.

```php
use ChatFlow\Telegram\Middleware\TypingMiddleware;

$bot->middleware([
    TypingMiddleware::class,
]);
```

The middleware swallows Telegram chat-action failures. Typing indicators must never break the actual flow.

## Order

Middleware is executed in registration order.

The handler or scene runs after middleware calls `$next($ctx)`.

## Dependency Injection

Middleware classes are resolved through the container. Telegram-specific middleware may type-hint Telegram SDK services such as `Telegram\Bot\Api`, but portable middleware should only depend on core services.
