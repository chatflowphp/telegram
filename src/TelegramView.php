<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\View\View;

final class TelegramView
{
    public static function options(View|string $view, TelegramMessageOptions $options): View
    {
        $normalized = $view instanceof View ? $view : View::text($view);
        $meta = $normalized->getMeta();
        $telegramMeta = $meta['telegram'] ?? [];
        if (!\is_array($telegramMeta)) {
            $telegramMeta = [];
        }

        $meta['telegram'] = array_merge($telegramMeta, $options->toMeta());

        return $normalized->withMeta($meta);
    }

    public static function forceReply(
        View|string $view,
        ?int $replyToMessageId = null,
        string $parseMode = 'HTML',
    ): View {
        return self::options(
            $view,
            (new TelegramMessageOptions(parseMode: $parseMode, replyToMessageId: $replyToMessageId))->withForceReply(),
        );
    }
}
