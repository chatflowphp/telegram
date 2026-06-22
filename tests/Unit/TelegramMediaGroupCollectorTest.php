<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Telegram\MediaGroup\InMemoryTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
use PHPUnit\Framework\TestCase;

final class TelegramMediaGroupCollectorTest extends TestCase
{
    public function test_only_latest_media_group_part_emits_aggregated_update(): void
    {
        $store = new InMemoryTelegramMediaGroupStore();
        $collector = new TelegramMediaGroupCollector($store, 0);
        $first = $this->update(1, 'photo_a');
        $second = $this->update(2, 'photo_b');

        $store->storePart('123:album-1', 2, $second);

        self::assertNull($collector->collect($first));
        $aggregated = $collector->collect($second);

        self::assertIsArray($aggregated);
        $messages = $aggregated['message']['__chatflow_media_group_messages'] ?? null;
        self::assertIsArray($messages);
        self::assertCount(2, $messages);
        self::assertSame(1, $messages[0]['message_id'] ?? null);
        self::assertSame(2, $messages[1]['message_id'] ?? null);
        self::assertSame([], $store->getParts('123:album-1'));
    }

    /**
     * @return array<string, mixed>
     */
    private function update(int $messageId, string $fileId): array
    {
        return [
            'update_id' => $messageId,
            'message' => [
                'message_id' => $messageId,
                'media_group_id' => 'album-1',
                'chat' => ['id' => 123],
                'photo' => [
                    [
                        'file_id' => $fileId,
                        'file_unique_id' => $fileId . '_unique',
                        'width' => 10,
                        'height' => 10,
                    ],
                ],
            ],
        ];
    }
}
