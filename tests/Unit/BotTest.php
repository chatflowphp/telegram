<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Core\Context;
use ChatFlow\Exception\LogicException;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Testing\MockHttpClient;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class BotTest extends TestCase
{
    private ?string $status = null;

    public function testUnsupportedTelegramEventTypesAreRejectedAtRegistration(): void
    {
        $bot = self::bot();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsupported Telegram event type "successful_payment"');

        $bot->onTelegramEvent('successful_payment', static fn(): null => null);
    }

    public function testMembershipUpdatesReachTheirHandler(): void
    {
        $bot = self::bot();
        $bot->onTelegramEvent('my_chat_member', [$this, 'captureStatus']);

        $result = $bot->handle(self::membershipUpdate());

        self::assertTrue($result->isSuccess());
        self::assertSame('kicked', $this->status);
    }

    /**
     * @param array<string, mixed> $update
     */
    public function captureStatus(Context $ctx, array $update): void
    {
        $membership = $update['my_chat_member'] ?? null;
        $member = \is_array($membership) ? $membership['new_chat_member'] ?? null : null;
        $status = \is_array($member) ? $member['status'] ?? null : null;

        $this->status = \is_string($status) ? $status : null;
    }

    private static function bot(): Bot
    {
        return new Bot(
            'TEST_TOKEN',
            sys_get_temp_dir() . '/chatflow-bot-test',
            api: new Api('TEST_TOKEN', false, new MockHttpClient()),
            storage: new MemoryStorage(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function membershipUpdate(): array
    {
        return [
            'update_id' => 1,
            'my_chat_member' => [
                'chat' => ['id' => 77, 'type' => 'private'],
                'from' => ['id' => 77],
                'date' => 0,
                'old_chat_member' => ['status' => 'member', 'user' => ['id' => 5]],
                'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 5]],
            ],
        ];
    }
}
