# Middleware

Core middleware wraps every handled update: routes, scenes, media handlers and Telegram event
handlers alike.

```php
$bot->middleware([TypingMiddleware::class, VisitorMiddleware::class]);
```

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

## Levels

| Level | Registration | Runs |
| --- | --- | --- |
| global | `$bot->middleware([...])` | for every update |
| scene | `BaseScene::getMiddlewares()` | while the scene is active |
| route | `$bot->onCommand(...)->middleware(...)` | when that route runs |

A middleware may return a core `Result` without calling `$next` to stop processing; nothing is
persisted in that case.

## Typing Middleware

`ChatFlow\Telegram\Middleware\TypingMiddleware` sends the `typing` chat action before
continuing and swallows failures: indicators must never break the flow.

## Dependency Injection

Middleware classes are resolved through the container. Telegram-specific middleware may
type-hint `Telegram\Bot\Api`; portable middleware depends on core services only.
