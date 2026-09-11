<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\MediaGroup;

interface TelegramMediaGroupStoreInterface
{
    /**
     * @param array<string, mixed> $update
     */
    public function storePart(string $groupKey, int $messageId, array $update): void;

    /**
     * @return list<array{message_id: int, update: array<string, mixed>}>
     */
    public function getParts(string $groupKey): array;

    /**
     * Elects the caller as the collector of the group. Returns true exactly once per group until
     * the group is deleted; other callers must treat the group as pending.
     */
    public function claim(string $groupKey): bool;

    public function deleteGroup(string $groupKey): void;
}
