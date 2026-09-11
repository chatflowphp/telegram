<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Exception;

use ChatFlow\Exception\BotException;
use Throwable;

/**
 * Telegram refused the call with 429 and asked to wait. Carries the wait Telegram reported, so a
 * caller can requeue the work instead of guessing.
 */
class TelegramRateLimitException extends BotException
{
    public function __construct(
        public readonly int $retryAfter,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message !== '' ? $message : \sprintf('Telegram rate limit reached; retry after %d second(s).', $retryAfter),
            429,
            $previous,
        );
    }
}
