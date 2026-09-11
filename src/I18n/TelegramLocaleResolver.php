<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\I18n;

use ChatFlow\Core\Context;
use ChatFlow\I18n\LocaleResolverInterface;

/**
 * The language Telegram reports for the user (`language_code`, for example "ru" or "en-GB").
 *
 * Telegram sends it with every message, so a bot speaks the user's language from the first
 * update, before anyone chose anything. It has no opinion when the update carries no user
 * (channel posts) or no language.
 */
final class TelegramLocaleResolver implements LocaleResolverInterface
{
    public function resolve(Context $ctx): ?string
    {
        $user = $ctx->getUser()?->getMeta()['user'] ?? null;
        $locale = \is_array($user) ? $user['language_code'] ?? null : null;

        return \is_string($locale) && $locale !== '' ? $locale : null;
    }
}
