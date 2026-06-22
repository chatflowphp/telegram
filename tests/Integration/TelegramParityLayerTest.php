<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

use ChatFlow\Core\Context;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\MediaGroup\InMemoryTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
use ChatFlow\Telegram\TelegramContext;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use ChatFlow\Telegram\UI\TelegramScreenManager;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class TelegramParityLayerTest extends TestCase
{
    private MockHttpClient $client;

    private Bot $bot;

    private TelegramBotTester $tester;

    protected function setUp(): void
    {
        $this->client = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $this->client);
        $this->bot = new Bot(
            token: 'TEST_TOKEN',
            basePath: dirname(__DIR__, 2),
            api: $api,
            mediaGroupCollector: new TelegramMediaGroupCollector(new InMemoryTelegramMediaGroupStore(), 0),
        );
        $this->bot->useStorage(new MemoryStorage());
        $this->tester = new TelegramBotTester($this->bot, $this->client);
    }

    public function test_telegram_context_force_reply_is_injected_into_route(): void
    {
        $this->bot->command('ask', static function (TelegramContext $telegram): void {
            $telegram->forceReply('Send the changed post text');
        });

        $this->tester->sendCommand('/ask')->assertSee('Send the changed post text');

        $params = $this->client->getRequests()[0]['params'];
        $replyMarkup = json_decode((string) ($params['reply_markup'] ?? ''), true);

        self::assertSame(['force_reply' => true], $replyMarkup);
        self::assertSame(1, $params['reply_to_message_id'] ?? null);
    }

    public function test_telegram_membership_event_route_can_reply(): void
    {
        $this->bot->onTelegramEvent('my_chat_member', static function (TelegramContext $telegram, array $update): void {
            $status = $update['my_chat_member']['new_chat_member']['status'] ?? 'unknown';
            $telegram->reply('Membership changed: ' . $status);
        });

        $this->tester->sendRawUpdate([
            'my_chat_member' => [
                'chat' => ['id' => -100123, 'type' => 'channel', 'title' => 'News'],
                'from' => ['id' => 456, 'is_bot' => false],
                'new_chat_member' => [
                    'user' => ['id' => 1, 'is_bot' => true],
                    'status' => 'administrator',
                ],
            ],
        ]);

        $this->tester->assertSee('Membership changed: administrator');
        self::assertSame('-100123', $this->client->getRequests()[0]['params']['chat_id'] ?? null);
    }

    public function test_on_media_handler_can_download_telegram_attachment(): void
    {
        $destination = sys_get_temp_dir() . '/chatflow-telegram-parity-' . bin2hex(random_bytes(6));

        $this->bot->onMedia('photo', static function (Context $ctx) use ($destination): void {
            $path = $ctx->downloadAttachment($destination);
            $ctx->reply($path === null ? 'No attachment' : 'Downloaded ' . basename($path));
        });

        try {
            $this->tester->sendMedia('photo', 'photo_file_12345678901234567890')->assertSee('Downloaded telegram_file_');
            self::assertSame('getFile', $this->client->getRequests()[0]['endpoint']);
        } finally {
            foreach (glob($destination . '/telegram_file_*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($destination);
        }
    }

    public function test_screen_manager_clears_tracked_message_group_best_effort(): void
    {
        $this->bot->command('screen', static function (
            TelegramScreenManager $screen,
            TelegramPublisher $publisher,
            Context $ctx,
        ): void {
            $screen->beginGroup('admin');
            $screen->track($publisher->sendMessage($ctx->getConversationId(), 'Admin screen'));
            $screen->clearGroup('admin');
            $ctx->reply('Screen refreshed');
        });

        $this->tester->sendCommand('/screen');

        self::assertSame(
            ['sendMessage', 'deleteMessage', 'sendMessage'],
            array_column($this->client->getRequests(), 'endpoint')
        );
        $this->tester->assertSee('Screen refreshed');
    }
}
