<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Telegram\TelegramMessageOptions;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\Telegram\TelegramRateLimiter;
use ChatFlow\Telegram\Testing\MockHttpClient;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class TelegramPublisherTest extends TestCase
{
    public function testEditReplyMarkupSendsEndpointWithSpecifiedMarkup(): void
    {
        $client = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $client);
        $publisher = new TelegramPublisher($api, new TelegramRateLimiter());

        $markup = [
            'inline_keyboard' => [
                [
                    ['text' => 'Click me', 'callback_data' => 'test:click'],
                ],
            ],
        ];

        $result = $publisher->editReplyMarkup(12345, 42, $markup);

        self::assertSame('editMessageReplyMarkup', $result->getEndpoint());
        self::assertSame(12345, $result->getChatId());
        self::assertSame(1, $result->getMessageId());

        $requests = $client->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('editMessageReplyMarkup', $requests[0]['endpoint']);
        self::assertSame(12345, $requests[0]['params']['chat_id']);
        self::assertSame(42, $requests[0]['params']['message_id']);
        self::assertSame(json_encode($markup), $requests[0]['params']['reply_markup']);
    }

    public function testEditReplyMarkupClearsButtonsWhenNullPassed(): void
    {
        $client = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $client);
        $publisher = new TelegramPublisher($api, new TelegramRateLimiter());

        $result = $publisher->editReplyMarkup(12345, 42, null);

        self::assertSame('editMessageReplyMarkup', $result->getEndpoint());

        $requests = $client->getRequests();
        self::assertCount(1, $requests);
        self::assertSame(json_encode(['inline_keyboard' => []]), $requests[0]['params']['reply_markup']);
    }

    public function testTelegramMessageOptionsWithReplyMarkup(): void
    {
        $markup = ['inline_keyboard' => [[['text' => 'Btn', 'callback_data' => 'data']]]];
        $options = TelegramMessageOptions::html()->withReplyMarkup($markup);

        $params = $options->toTelegramParams();
        self::assertSame('HTML', $params['parse_mode']);
        self::assertSame(json_encode($markup), $params['reply_markup']);

        $cleared = $options->withoutReplyMarkup();
        self::assertSame(json_encode(['inline_keyboard' => []]), $cleared->toTelegramParams()['reply_markup']);
    }
}
