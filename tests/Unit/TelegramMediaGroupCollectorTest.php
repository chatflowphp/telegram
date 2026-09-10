<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Telegram\MediaGroup\InMemoryTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramMediaGroupCollectorTest extends TestCase
{
    #[DataProvider('messageKeys')]
    public function testOnlyTheLatestPartEmitsTheAggregatedUpdate(string $key): void
    {
        $store = new InMemoryTelegramMediaGroupStore();
        $collector = new TelegramMediaGroupCollector($store, 0);
        $first = $this->update($key, 1, 'photo_a');
        $second = $this->update($key, 2, 'photo_b');

        $store->storePart('123:album-1', 2, $second);

        self::assertNull($collector->collect($first));
        $aggregated = $collector->collect($second);

        self::assertIsArray($aggregated);
        self::assertArrayNotHasKey($key === 'message' ? 'edited_message' : 'message', $aggregated);
        $message = $aggregated[$key];
        self::assertIsArray($message);
        $chat = $message['chat'] ?? null;
        self::assertIsArray($chat);
        self::assertSame(123, $chat['id'] ?? null, 'the aggregated update keeps the chat');
        $messages = $message[TelegramMediaGroupCollector::AGGREGATE_KEY] ?? null;
        self::assertIsArray($messages);
        self::assertCount(2, $messages);
        self::assertSame([1, 2], array_map(static fn(mixed $part): mixed => \is_array($part) ? ($part['message_id'] ?? null) : null, $messages));
        self::assertSame([], $store->getParts('123:album-1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function messageKeys(): iterable
    {
        yield 'message' => ['message'];
        yield 'edited_message' => ['edited_message'];
    }

    public function testUpdatesWithoutMediaGroupPassThrough(): void
    {
        $collector = new TelegramMediaGroupCollector(new InMemoryTelegramMediaGroupStore(), 0);
        $update = ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => 1], 'text' => 'hi']];

        self::assertSame($update, $collector->collect($update));
        self::assertSame(['update_id' => 2], $collector->collect(['update_id' => 2]));
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $key, int $messageId, string $fileId): array
    {
        return [
            'update_id' => $messageId,
            $key => [
                'message_id' => $messageId,
                'media_group_id' => 'album-1',
                'chat' => ['id' => 123],
                'from' => ['id' => 456],
                'photo' => [['file_id' => $fileId, 'file_unique_id' => $fileId . '_unique', 'width' => 10, 'height' => 10]],
            ],
        ];
    }
}
