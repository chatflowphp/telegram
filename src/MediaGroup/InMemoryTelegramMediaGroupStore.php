<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\MediaGroup;

final class InMemoryTelegramMediaGroupStore implements TelegramMediaGroupStoreInterface
{
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $groups = [];

    public function storePart(string $groupKey, int $messageId, array $update): void
    {
        $this->groups[$groupKey][$messageId] = $update;
    }

    public function getParts(string $groupKey): array
    {
        $parts = [];
        foreach ($this->groups[$groupKey] ?? [] as $messageId => $update) {
            $parts[] = [
                'message_id' => $messageId,
                'update' => $update,
            ];
        }

        usort($parts, static fn(array $a, array $b): int => $a['message_id'] <=> $b['message_id']);

        return $parts;
    }

    public function deleteGroup(string $groupKey): void
    {
        unset($this->groups[$groupKey]);
    }
}
