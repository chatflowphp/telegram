<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Exception;

use ChatFlow\Exception\BotException;

/**
 * A text handed to an API that sends exactly one message does not fit the Bot API limit.
 *
 * Conversational replies are split automatically; the publisher raises this instead, because the
 * caller asked for one message and has to decide how to shorten it.
 */
class TelegramMessageTooLongException extends BotException
{
    public function __construct(
        public readonly int $length,
        public readonly int $limit,
        string $field = 'text',
    ) {
        parent::__construct(\sprintf(
            'Telegram %s is limited to %d characters, got %d. Shorten it, or send it as a conversational reply, which is split automatically.',
            $field,
            $limit,
            $length,
        ));
    }
}
