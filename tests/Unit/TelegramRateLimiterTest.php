<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Core\Context;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Exception\TelegramRateLimitException;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\Telegram\TelegramRateLimiter;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;

final class TelegramRateLimiterTest extends TestCase
{
    /**
     * @var list<int>
     */
    private array $slept = [];

    public function testAShortWaitIsSleptThroughAndTheCallRepeated(): void
    {
        $client = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $client);
        $client->rateLimitEndpoint('sendMessage', retryAfter: 1);

        $this->limiter()->run(fn(): mixed => $api->sendMessage(['chat_id' => 1, 'text' => 'hi']));

        self::assertSame([1], $this->slept);
        self::assertCount(2, $client->getRequests(), 'The call was repeated after the wait.');
    }

    public function testALongWaitIsReportedInsteadOfBlockingTheWorker(): void
    {
        $client = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $client);
        $client->rateLimitEndpoint('sendMessage', retryAfter: 60);

        try {
            $this->limiter()->run(fn(): mixed => $api->sendMessage(['chat_id' => 1, 'text' => 'hi']));
            self::fail('Expected a rate limit exception.');
        } catch (TelegramRateLimitException $exception) {
            self::assertSame(60, $exception->retryAfter);
        }

        self::assertSame([], $this->slept, 'A minute of waiting is never slept through.');
        self::assertCount(1, $client->getRequests());
    }

    public function testRetriesAreBounded(): void
    {
        $client = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $client);
        $client->rateLimitEndpoint('sendMessage', retryAfter: 1, times: 5);

        try {
            $this->limiter()->run(fn(): mixed => $api->sendMessage(['chat_id' => 1, 'text' => 'hi']));
            self::fail('Expected a rate limit exception.');
        } catch (TelegramRateLimitException $exception) {
            self::assertSame(1, $exception->retryAfter);
        }

        self::assertSame([1], $this->slept);
        self::assertCount(2, $client->getRequests());
    }

    public function testOtherApiErrorsPassThroughUnchanged(): void
    {
        $client = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $client);
        $client->failEndpoint('sendMessage', 'Bad Request: chat not found');

        $this->expectException(TelegramResponseException::class);

        $this->limiter()->run(fn(): mixed => $api->sendMessage(['chat_id' => 1, 'text' => 'hi']));
    }

    public function testThePublisherHandsTheWaitToTheCaller(): void
    {
        $client = new MockHttpClient();
        $publisher = new TelegramPublisher(new Api('TEST_TOKEN', false, $client), $this->limiter());
        $client->rateLimitEndpoint('sendMessage', retryAfter: 30);

        try {
            $publisher->sendMessage('123', 'broadcast');
            self::fail('Expected a rate limit exception.');
        } catch (TelegramRateLimitException $exception) {
            self::assertSame(30, $exception->retryAfter);
        }
    }

    public function testAThrottledReplyFailsDeliveryButKeepsTheConversation(): void
    {
        $client = new MockHttpClient();
        $bot = new Bot(
            'TEST_TOKEN',
            sys_get_temp_dir() . '/chatflow-rate-limit-test',
            api: new Api('TEST_TOKEN', false, $client),
            storage: new MemoryStorage(),
        );
        $bot->command('start', static function (Context $ctx): void {
            $ctx->session()->set('seen', true);
            $ctx->reply('Welcome');
        });

        $tester = new TelegramBotTester($bot, $client);
        $client->rateLimitEndpoint('sendMessage', retryAfter: 60);

        $tester->sendCommand('start');

        $result = $tester->getLastResult();

        self::assertNotNull($result);
        self::assertTrue($result->isError(), 'A throttled delivery is not a success.');
        $tester->assertSessionHas('seen', true);
    }

    private function limiter(): TelegramRateLimiter
    {
        return new TelegramRateLimiter(
            maxRetries: 1,
            maxWaitSeconds: 3,
            sleeper: function (int $seconds): void {
                $this->slept[] = $seconds;
            },
        );
    }
}
