<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\MiniShop;

use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;

final class MiniShopVisitorMiddleware implements MiddlewareInterface
{
    public function process(Context $ctx, callable $next): mixed
    {
        $user = $ctx->getUser();
        $meta = $user?->getMeta() ?? [];
        $nestedUser = \is_array($meta['user'] ?? null) ? $meta['user'] : [];
        $username = $meta['username'] ?? ($nestedUser['username'] ?? null);

        $label = \is_string($username) && $username !== ''
            ? '@' . $username
            : \sprintf('user-%s', (string) ($user?->getId() ?? $ctx->getConversationId()));

        $ctx->set('visitor', $label);

        return $next($ctx);
    }
}
