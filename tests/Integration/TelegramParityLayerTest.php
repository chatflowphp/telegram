<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

use ChatFlow\Core\Context;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\MediaGroup\InMemoryTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
use ChatFlow\Telegram\TelegramContext;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use ChatFlow\Telegram\Tests\Fixtures\SurveyScene;
use ChatFlow\Telegram\UI\TelegramScreenManager;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class TelegramParityLayerTest extends TestCase
{
    private MockHttpClient $client;

    private MemoryStorage $storage;

    private Bot $bot;

    private TelegramBotTester $tester;

    protected function setUp(): void
    {
        $this->client = new MockHttpClient();
        $this->storage = new MemoryStorage();
        $this->bot = new Bot(
            token: 'TEST_TOKEN',
            basePath: \dirname(__DIR__, 2),
            api: new Api('TEST_TOKEN', false, $this->client),
            mediaGroupCollector: new TelegramMediaGroupCollector(new InMemoryTelegramMediaGroupStore(), 0),
            storage: $this->storage,
        );
        $this->tester = new TelegramBotTester($this->bot, $this->client);
    }

    public function testTelegramContextForceReplyIsInjectedIntoRoute(): void
    {
        $this->bot->command('ask', static function (TelegramContext $telegram): void {
            $telegram->forceReply('Send the changed post text');
        });

        $this->tester->sendCommand('/ask')->assertSee('Send the changed post text');

        $params = $this->client->getRequests()[0]['params'];
        self::assertIsString($params['reply_markup'] ?? null);

        self::assertSame(['force_reply' => true], json_decode($params['reply_markup'], true));
        self::assertSame(1, $params['reply_to_message_id'] ?? null);
    }

    public function testTelegramMembershipEventRouteCanReply(): void
    {
        $this->bot->onTelegramEvent('my_chat_member', static function (TelegramContext $telegram, array $update): void {
            $member = $update['my_chat_member'] ?? null;
            $newMember = \is_array($member) ? ($member['new_chat_member'] ?? null) : null;
            $status = \is_array($newMember) ? ($newMember['status'] ?? null) : null;
            $telegram->reply('Membership changed: ' . (\is_string($status) ? $status : 'unknown'));
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

    public function testMiddlewareRunsForMediaAndTelegramEventHandlers(): void
    {
        $seen = new \ArrayObject();
        $this->bot->middleware([new class ($seen) implements MiddlewareInterface {
            /**
             * @param \ArrayObject<int, string> $seen
             */
            public function __construct(private readonly \ArrayObject $seen) {}

            public function process(Context $ctx, callable $next): mixed
            {
                $this->seen->append($ctx->getConversationId());

                return $next($ctx);
            }
        }]);
        $this->bot->onMedia('photo', static function (Context $ctx): void {
            $ctx->reply('photo received');
        });
        $this->bot->onTelegramEvent('my_chat_member', static function (Context $ctx): void {
            $ctx->reply('membership');
        });
        $this->bot->command('start', static fn(): null => null);

        $this->tester->sendCommand('/start');
        $this->tester->sendMedia('photo')->assertSee('photo received');
        $this->tester->sendRawUpdate([
            'my_chat_member' => [
                'chat' => ['id' => 777, 'type' => 'private'],
                'from' => ['id' => 456, 'is_bot' => false],
                'new_chat_member' => ['user' => ['id' => 1, 'is_bot' => true], 'status' => 'member'],
            ],
        ])->assertSee('membership');

        self::assertSame(['123456789', '123456789', '777'], $seen->getArrayCopy());
    }

    public function testMediaGoesToTheActiveSceneInsteadOfTheMediaHandler(): void
    {
        $this->bot->registerScene(SurveyScene::class);
        $this->bot->command('survey', static function (Context $ctx): void {
            $ctx->enter(SurveyScene::class);
        });
        $this->bot->onMedia('photo', static function (Context $ctx): void {
            $ctx->reply('photo handler');
        });

        $this->tester
            ->sendCommand('/survey')
            ->clear()
            ->sendMedia('photo')
            ->assertDontSee('photo handler')
            ->assertScene(SurveyScene::class);
    }

    public function testUpdatesWithoutAChatAreSkippedWithoutCreatingConversations(): void
    {
        $this->bot->fallback(static function (Context $ctx): void {
            $ctx->reply('fallback');
        });

        $this->tester
            ->sendRawUpdate(['inline_query' => ['id' => '1', 'from' => ['id' => 5], 'query' => 'hi', 'offset' => '']])
            ->assertResult('no_match', 'unsupported_update')
            ->assertEndpointNotCalled('sendMessage');

        self::assertSame([], $this->storage->keys());
    }

    public function testOnMediaHandlerCanDownloadTelegramAttachment(): void
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
            $files = glob($destination . '/telegram_file_*');

            foreach ($files === false ? [] : $files as $path) {
                @unlink($path);
            }

            @rmdir($destination);
        }
    }

    public function testScreenManagerTracksMessagesAcrossRequests(): void
    {
        $this->bot->command('screen', static function (TelegramScreenManager $screen, TelegramPublisher $publisher, Context $ctx): void {
            $screen->beginGroup('admin');
            $screen->track($publisher->sendMessage($ctx->getConversationId(), 'Admin screen'));
            $ctx->reply('Screen refreshed');
        });

        $this->tester->sendCommand('/screen');
        self::assertSame(['sendMessage', 'sendMessage'], array_column($this->client->getRequests(), 'endpoint'));

        $this->tester->clear()->sendCommand('/screen');
        self::assertSame(['deleteMessage', 'sendMessage', 'sendMessage'], array_column($this->client->getRequests(), 'endpoint'));
        $tracked = $this->tester->conversation()->getContext()->getExtension('telegram.screens')['admin'] ?? null;
        self::assertIsArray($tracked);
        self::assertCount(1, $tracked);
    }

    public function testWebhookSecretIsComparedInConstantTime(): void
    {
        $bot = new Bot('TEST_TOKEN', \dirname(__DIR__, 2), webhookSecret: 'top-secret', api: new Api('TEST_TOKEN', false, $this->client));

        self::assertTrue($bot->verifyWebhookSecret('top-secret'));
        self::assertFalse($bot->verifyWebhookSecret('top-secre'));
        self::assertFalse($bot->verifyWebhookSecret(null));
        self::assertTrue($this->bot->verifyWebhookSecret(null), 'no secret configured');
    }

    public function testStorageCannotChangeAfterTheFirstUpdate(): void
    {
        $this->bot->command('start', static fn(): null => null);
        $this->tester->sendCommand('/start');

        $this->expectException(\ChatFlow\Exception\LogicException::class);
        $this->bot->useStorage(new MemoryStorage());
    }
}
