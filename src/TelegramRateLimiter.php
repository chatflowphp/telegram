<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Telegram\Exception\TelegramRateLimitException;
use Closure;
use Telegram\Bot\Exceptions\TelegramResponseException;

/**
 * Runs a Bot API call and reacts to 429 the way Telegram asks: wait `retry_after` seconds and try
 * again.
 *
 * Only short waits are slept through, because a webhook worker cannot be blocked for a minute; a
 * longer wait becomes a TelegramRateLimitException carrying the delay, so the caller can requeue
 * the work.
 */
final class TelegramRateLimiter
{
    /**
     * @var Closure(int): void
     */
    private readonly Closure $sleeper;

    /**
     * @param int $maxRetries How many times a throttled call is repeated
     * @param int $maxWaitSeconds Longest wait this limiter sleeps through
     * @param (callable(int): void)|null $sleeper Replaced in tests to avoid real waiting
     */
    public function __construct(
        private readonly int $maxRetries = 1,
        private readonly int $maxWaitSeconds = 3,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper !== null
            ? $sleeper(...)
            : static function (int $seconds): void {
                sleep($seconds);
            };
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws TelegramRateLimitException When the wait is too long or the retries are used up
     */
    public function run(callable $operation): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (TelegramResponseException $exception) {
                $retryAfter = self::retryAfter($exception);

                if ($retryAfter === null) {
                    throw $exception;
                }

                if ($attempt >= $this->maxRetries || $retryAfter > $this->maxWaitSeconds) {
                    throw new TelegramRateLimitException($retryAfter, previous: $exception);
                }

                $attempt++;
                ($this->sleeper)($retryAfter);
            }
        }
    }

    /**
     * The wait Telegram asked for, or null when the failure was not a rate limit.
     */
    public static function retryAfter(TelegramResponseException $exception): ?int
    {
        if ($exception->getCode() !== 429 && !str_contains(strtolower($exception->getMessage()), 'too many requests')) {
            return null;
        }

        $parameters = $exception->get('parameters');
        $retryAfter = \is_array($parameters) ? $parameters['retry_after'] ?? null : null;

        return \is_int($retryAfter) ? max(0, $retryAfter) : 0;
    }
}
