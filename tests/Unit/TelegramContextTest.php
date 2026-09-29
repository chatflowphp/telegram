<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Core\Context;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\TelegramContext;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class TelegramContextTest extends TestCase
{
    public function testRemoveButtonsEditsReplyMarkupViaPublisher(): void
    {
        $client = new MockHttpClient();
        $bot = new Bot(
            'TEST_TOKEN',
            sys_get_temp_dir() . '/chatflow-tg-ctx-test',
            api: new Api('TEST_TOKEN', false, $client),
            storage: new MemoryStorage(),
        );

        $tester = new TelegramBotTester($bot, $client);

        $removed = false;
        $bot->onAction('approve', static function (Context $ctx) use (&$removed): void {
            $tg = new TelegramContext($ctx);
            self::assertInstanceOf(TelegramPublisher::class, $tg->publisher());
            self::assertInstanceOf(Api::class, $tg->api());
            self::assertSame('123456789', (string) $tg->getChatId());

            $result = $tg->removeButtons();
            self::assertNotNull($result);
            self::assertSame('editMessageReplyMarkup', $result->getEndpoint());
            $removed = true;
        });

        $tester->clickButton('approve');

        self::assertTrue($removed);

        $requests = array_filter(
            $client->getRequests(),
            static fn(array $req): bool => $req['endpoint'] === 'editMessageReplyMarkup',
        );
        self::assertCount(1, $requests);
        $request = array_values($requests)[0];
        self::assertSame(json_encode(['inline_keyboard' => []]), $request['params']['reply_markup']);
    }

    public function testEditReplyMarkupSetsCustomMarkup(): void
    {
        $client = new MockHttpClient();
        $bot = new Bot(
            'TEST_TOKEN',
            sys_get_temp_dir() . '/chatflow-tg-ctx-test',
            api: new Api('TEST_TOKEN', false, $client),
            storage: new MemoryStorage(),
        );

        $tester = new TelegramBotTester($bot, $client);

        $customMarkup = [
            'inline_keyboard' => [
                [['text' => 'New button', 'callback_data' => 'new:data']],
            ],
        ];

        $edited = false;
        $bot->onAction('edit', static function (Context $ctx) use ($customMarkup, &$edited): void {
            $tg = new TelegramContext($ctx);
            $result = $tg->editReplyMarkup($customMarkup);
            self::assertNotNull($result);
            self::assertSame('editMessageReplyMarkup', $result->getEndpoint());
            $edited = true;
        });

        $tester->clickButton('edit');

        self::assertTrue($edited);

        $requests = array_filter(
            $client->getRequests(),
            static fn(array $req): bool => $req['endpoint'] === 'editMessageReplyMarkup',
        );
        self::assertCount(1, $requests);
        $request = array_values($requests)[0];
        self::assertSame(json_encode($customMarkup), $request['params']['reply_markup']);
    }
}
