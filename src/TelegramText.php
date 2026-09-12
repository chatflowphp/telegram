<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

/**
 * The text limits of the Bot API and the splitting a bot needs to stay inside them.
 *
 * Long texts are cut on the most natural boundary that fits: a line break, then a space, and only
 * a very long word is cut mid-word.
 */
final class TelegramText
{
    public const MESSAGE_LIMIT = 4096;

    public const CAPTION_LIMIT = 1024;

    public const ACK_LIMIT = 200;

    public static function exceeds(string $text, int $limit): bool
    {
        return mb_strlen($text) > $limit;
    }

    public static function truncate(string $text, int $limit): string
    {
        if (!self::exceeds($text, $limit)) {
            return $text;
        }

        return mb_substr($text, 0, max(0, $limit - 1)) . '…';
    }

    /**
     * @return list<string> The text in pieces that each fit the limit; never empty
     */
    public static function split(string $text, int $limit = self::MESSAGE_LIMIT): array
    {
        if ($limit < 1 || !self::exceeds($text, $limit)) {
            return [$text];
        }

        $chunks = [];
        $current = '';

        foreach (explode("\n", $text) as $line) {
            $candidate = $current === '' ? $line : $current . "\n" . $line;

            if (!self::exceeds($candidate, $limit)) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
            }

            $parts = self::splitLine($line, $limit);
            $current = (string) array_pop($parts);

            foreach ($parts as $part) {
                $chunks[] = $part;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [$text] : $chunks;
    }

    /**
     * @return list<string>
     */
    private static function splitLine(string $line, int $limit): array
    {
        if (!self::exceeds($line, $limit)) {
            return [$line];
        }

        $parts = [];
        $current = '';

        foreach (explode(' ', $line) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (!self::exceeds($candidate, $limit)) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $parts[] = $current;
                $current = '';
            }

            while (self::exceeds($word, $limit)) {
                $parts[] = mb_substr($word, 0, $limit);
                $word = mb_substr($word, $limit);
            }

            $current = $word;
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts === [] ? [''] : $parts;
    }
}
