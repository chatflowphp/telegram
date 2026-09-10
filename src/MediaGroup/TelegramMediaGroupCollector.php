<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\MediaGroup;

use InvalidArgumentException;

/**
 * Aggregates the separate updates Telegram sends for one album into a single update.
 *
 * Each part is stored, the collector waits for the configured window, and only the part with the
 * highest message id emits the aggregated update (under `__chatflow_media_group_messages` inside
 * the message). Earlier parts return null and must be ignored by the caller.
 */
final class TelegramMediaGroupCollector
{
    public const AGGREGATE_KEY = '__chatflow_media_group_messages';

    public function __construct(
        private readonly TelegramMediaGroupStoreInterface $store,
        private readonly int $waitWindowMs = 1200,
    ) {
        if ($this->waitWindowMs < 0) {
            throw new InvalidArgumentException('Telegram media group wait window must be zero or positive.');
        }
    }

    /**
     * @param array<string, mixed> $update
     *
     * @return array<string, mixed>|null
     */
    public function collect(array $update): ?array
    {
        $messageKey = self::messageKey($update);

        if ($messageKey === null) {
            return $update;
        }

        $message = $update[$messageKey];

        if (!\is_array($message) || isset($message[self::AGGREGATE_KEY])) {
            return $update;
        }

        $mediaGroupId = $message['media_group_id'] ?? null;
        $messageId = $message['message_id'] ?? null;

        if (!\is_string($mediaGroupId) || $mediaGroupId === '' || !\is_int($messageId)) {
            return $update;
        }

        $chat = $message['chat'] ?? [];
        $chatId = \is_array($chat) && isset($chat['id']) && \is_scalar($chat['id']) ? (string) $chat['id'] : '0';
        $groupKey = $chatId . ':' . $mediaGroupId;

        $this->store->storePart($groupKey, $messageId, $update);

        if ($this->waitWindowMs > 0) {
            usleep($this->waitWindowMs * 1000);
        }

        $parts = $this->store->getParts($groupKey);
        $latestMessageId = $messageId;

        foreach ($parts as $part) {
            $latestMessageId = max($latestMessageId, $part['message_id']);
        }

        if ($messageId !== $latestMessageId) {
            return null;
        }

        $messages = [];

        foreach ($parts as $part) {
            $partKey = self::messageKey($part['update']);
            $partMessage = $partKey === null ? null : $part['update'][$partKey];

            if (\is_array($partMessage)) {
                $messages[] = $partMessage;
            }
        }

        $message[self::AGGREGATE_KEY] = $messages;
        $update[$messageKey] = $message;
        $this->store->deleteGroup($groupKey);

        return $update;
    }

    /**
     * @param array<string, mixed> $update
     */
    private static function messageKey(array $update): ?string
    {
        foreach (['message', 'edited_message'] as $key) {
            if (\is_array($update[$key] ?? null)) {
                return $key;
            }
        }

        return null;
    }
}
