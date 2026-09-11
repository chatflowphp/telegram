<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Telegram\MediaGroup\FileTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\InMemoryTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramMediaGroupCollectorTest extends TestCase
{
    #[DataProvider('messageKeys')]
    public function testTheFirstPartClaimsTheAlbumAndEmitsEverythingCollected(string $key): void
    {
        $store = new InMemoryTelegramMediaGroupStore();
        $collector = new TelegramMediaGroupCollector($store, 0);
        $first = $this->update($key, 1, 'photo_a');
        $second = $this->update($key, 2, 'photo_b');

        // The second part arrived (and was stored) while the first request was still running.
        $store->storePart('123:album-1', 2, $second);

        $aggregated = $collector->collect($first);

        self::assertIsArray($aggregated);
        self::assertArrayNotHasKey($key === 'message' ? 'edited_message' : 'message', $aggregated);
        $message = $aggregated[$key];
        self::assertIsArray($message);
        $chat = $message['chat'] ?? null;
        self::assertIsArray($chat);
        self::assertSame(123, $chat['id'] ?? null, 'the aggregated update keeps the chat');
        $messages = $message[TelegramMediaGroupCollector::AGGREGATE_KEY] ?? null;
        self::assertIsArray($messages);
        self::assertSame([1, 2], array_map(static fn(mixed $part): mixed => \is_array($part) ? ($part['message_id'] ?? null) : null, $messages));
        self::assertSame([], $store->getParts('123:album-1'));
        self::assertTrue($store->claim('123:album-1'), 'the album can be collected again once it was emitted');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function messageKeys(): iterable
    {
        yield 'message' => ['message'];
        yield 'edited_message' => ['edited_message'];
    }

    /**
     * @param callable(): TelegramMediaGroupStoreInterface $factory
     */
    #[DataProvider('stores')]
    public function testPartsSeenWhileAnotherRequestCollectsTheAlbumArePending(callable $factory): void
    {
        $store = $factory();
        $collector = new TelegramMediaGroupCollector($store, 0);

        self::assertTrue($store->claim('123:album-1'));
        self::assertFalse($store->claim('123:album-1'));
        self::assertNull($collector->collect($this->update('message', 2, 'photo_b')));
        self::assertCount(1, $store->getParts('123:album-1'), 'the pending part is stored for the leader');

        $store->deleteGroup('123:album-1');
        self::assertSame([], $store->getParts('123:album-1'));
        self::assertTrue($store->claim('123:album-1'));
    }

    /**
     * @return iterable<string, array{callable(): TelegramMediaGroupStoreInterface}>
     */
    public static function stores(): iterable
    {
        yield 'memory' => [static fn(): TelegramMediaGroupStoreInterface => new InMemoryTelegramMediaGroupStore()];
        yield 'file' => [static fn(): TelegramMediaGroupStoreInterface => new FileTelegramMediaGroupStore(
            sys_get_temp_dir() . '/chatflow-media-groups-' . bin2hex(random_bytes(4)),
            cleanupProbability: 0,
        )];
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
