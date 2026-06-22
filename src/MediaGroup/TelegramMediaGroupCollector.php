<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\MediaGroup;

use InvalidArgumentException;

final class TelegramMediaGroupCollector
{
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
        $message = $this->extractMessage($update);
        if ($message === null) {
            return $update;
        }

        if (isset($message['__chatflow_media_group_messages']) && is_array($message['__chatflow_media_group_messages'])) {
            return $update;
        }

        $mediaGroupId = $message['media_group_id'] ?? null;
        if (!is_string($mediaGroupId) || $mediaGroupId === '') {
            return $update;
        }

        $messageId = $message['message_id'] ?? null;
        if (!is_int($messageId)) {
            return $update;
        }

        $chat = $message['chat'] ?? [];
        $chatId = is_array($chat) && isset($chat['id']) && is_scalar($chat['id']) ? (string) $chat['id'] : '0';
        $groupKey = $chatId . ':' . $mediaGroupId;

        $this->store->storePart($groupKey, $messageId, $update);

        if ($this->waitWindowMs > 0) {
            usleep($this->waitWindowMs * 1000);
        }

        $parts = $this->store->getParts($groupKey);
        $latestMessageId = $parts === [] ? $messageId : max(array_column($parts, 'message_id'));
        if ($messageId !== $latestMessageId) {
            return null;
        }

        $messages = [];
        foreach ($parts as $part) {
            $partMessage = $this->extractMessage($part['update']);
            if ($partMessage !== null) {
                $messages[] = $partMessage;
            }
        }

        $update['message']['__chatflow_media_group_messages'] = $messages;
        $this->store->deleteGroup($groupKey);

        return $update;
    }

    /**
     * @param array<string, mixed> $update
     *
     * @return array<string, mixed>|null
     */
    private function extractMessage(array $update): ?array
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;

        return is_array($message) ? $message : null;
    }
}
