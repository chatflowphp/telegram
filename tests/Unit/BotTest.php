<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Core\Context;
use ChatFlow\Exception\LogicException;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Testing\MockHttpClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Telegram\Bot\Api;
use Throwable;

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

    private static function bot(?MockHttpClient $client = null): Bot
    {
        return new Bot(
            'TEST_TOKEN',
            sys_get_temp_dir() . '/chatflow-bot-test',
            api: new Api('TEST_TOKEN', false, $client ?? new MockHttpClient()),
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

    public function testUpdatesWithoutAChatReachTheirRawHandler(): void
    {
        $client = new MockHttpClient();
        $bot = self::bot($client);
        $bot->onRawUpdate('pre_checkout_query', [$this, 'approveCheckout']);

        $result = $bot->handle(self::preCheckoutUpdate());

        self::assertTrue($result->isSuccess());
        self::assertSame('raw_update_processed', $result->getMessage());
        self::assertSame(['answerPreCheckoutQuery'], array_column($client->getRequests(), 'endpoint'));
        self::assertSame('q1', $client->getRequests()[0]['params']['pre_checkout_query_id'] ?? null);
    }

    public function testRawHandlersCannotShadowTheConversationRuntime(): void
    {
        $bot = self::bot();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"message" is handled by the conversation runtime');

        $bot->onRawUpdate('message', static fn(): null => null);
    }

    public function testUpdatesWithoutAChatAndWithoutAHandlerAreSkipped(): void
    {
        $result = self::bot()->handle(self::preCheckoutUpdate());

        self::assertTrue($result->isNoMatch());
        self::assertSame('unsupported_update', $result->getMessage());
    }

    public function testAFailingRawHandlerIsReportedWithoutAContext(): void
    {
        $bot = self::bot();
        $caught = null;
        $contextWasNull = false;

        $bot->onException(RuntimeException::class, static function (Throwable $exception, ?Context $context) use (&$caught, &$contextWasNull): void {
            $caught = $exception;
            $contextWasNull = $context === null;
        });
        $bot->onRawUpdate('pre_checkout_query', static function (): void {
            throw new RuntimeException('checkout service is down');
        });

        $result = $bot->handle(self::preCheckoutUpdate());

        self::assertTrue($result->isError());
        self::assertSame('raw_update_failed', $result->getMessage());
        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertTrue($contextWasNull, 'A raw update has no chat, so error handlers get no context.');
    }

    /**
     * @param array<string, mixed> $update
     */
    public function approveCheckout(Api $api, array $update): void
    {
        $query = $update['pre_checkout_query'] ?? null;
        $id = \is_array($query) ? $query['id'] ?? null : null;

        $api->answerPreCheckoutQuery([
            'pre_checkout_query_id' => \is_string($id) ? $id : '',
            'ok' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function preCheckoutUpdate(): array
    {
        return [
            'update_id' => 2,
            'pre_checkout_query' => [
                'id' => 'q1',
                'from' => ['id' => 5],
                'currency' => 'XTR',
                'total_amount' => 100,
                'invoice_payload' => 'order-42',
            ],
        ];
    }
}
