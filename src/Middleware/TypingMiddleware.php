<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Middleware;

use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;
use Telegram\Bot\Actions;
use Telegram\Bot\Api;
use Throwable;

final class TypingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Api $api
    ) {
    }

    public function process(Context $ctx, callable $next): mixed
    {
        try {
            $this->api->sendChatAction([
                'chat_id' => $ctx->getConversationId(),
                'action' => Actions::TYPING,
            ]);
        } catch (Throwable) {
        }

        return $next($ctx);
    }
}
