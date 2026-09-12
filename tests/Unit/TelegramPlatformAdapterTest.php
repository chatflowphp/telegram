<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Event\MessageRef;
use ChatFlow\Event\UserRef;
use ChatFlow\Exception\UnsupportedInputException;
use ChatFlow\Outbound\AckEffect;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\Telegram\Callback\FileTelegramCallbackStore;
use ChatFlow\Telegram\Callback\InMemoryTelegramCallbackStore;
use ChatFlow\Telegram\Callback\TelegramCallbackPayloadEncoder;
use ChatFlow\Telegram\FileDownloader;
use ChatFlow\Telegram\Media\TelegramMedia;
use ChatFlow\Telegram\Media\TelegramMediaSource;
use ChatFlow\Telegram\MediaGroup\FileTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
use ChatFlow\Telegram\TelegramPlatformAdapter;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\Telegram\TelegramView;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Tests\Contracts\PlatformAdapterContractAssertions;
use ChatFlow\View\Action;
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Telegram\Bot\Api;

final class TelegramPlatformAdapterTest extends TestCase
{
    private TelegramCallbackPayloadEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new TelegramCallbackPayloadEncoder(new InMemoryTelegramCallbackStore(), 'test-secret');
    }

    public function testMessageUpdateIsNormalizedToTypedInboundEvent(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $event = $adapter->createInboundEvent([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'text' => '/start',
                'chat' => ['id' => 123],
                'from' => ['id' => 456, 'username' => 'tester'],
            ],
        ]);

        $ref = $event->getMessageRef();

        self::assertSame('123', $event->getConversationId());
        self::assertSame(456, $event->getUserId());
        self::assertSame('/start', $event->getText());
        self::assertNotNull($ref);
        self::assertSame('10', $ref->getId());
        self::assertSame('message', $ref->get('update_type'));
        PlatformAdapterContractAssertions::assertInboundEventSatisfiesCoreContract($event);
    }

    public function testCallbackQueryIsNormalizedToActionEvent(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $event = $adapter->createInboundEvent($this->callbackUpdate($this->encoder->encode(new Action('menu:open', 'Menu', ['page' => 1]))));

        $ref = $event->getMessageRef();

        self::assertTrue($event->isAction());
        self::assertSame('menu:open', $event->getActionId());
        self::assertSame(['page' => 1], $event->getActionPayload());
        self::assertNotNull($ref);
        self::assertSame('cb-1', $ref->getReplyToken());
        self::assertSame('accepted', $ref->get('callback_status'));
        PlatformAdapterContractAssertions::assertInboundEventSatisfiesCoreContract($event);
    }

    public function testForgedCallbackDataIsRejected(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $this->expectException(UnsupportedInputException::class);
        $this->expectExceptionMessage('callback data rejected');

        $adapter->createInboundEvent($this->callbackUpdate('{"id":"order:pay","payload":{"amount":0}}'));
    }

    public function testExpiredStoredCallbacksBecomeEventsWithoutAnAction(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $event = $adapter->createInboundEvent($this->callbackUpdate('cf:0123456789abcdef'));

        self::assertFalse($event->isAction());
        self::assertSame('expired', $event->getMessageRef()?->get('callback_status'));
    }

    public function testUpdatesWithoutAChatAreUnsupported(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $this->expectException(UnsupportedInputException::class);
        $this->expectExceptionMessage('"inline_query"');

        $adapter->createInboundEvent(['update_id' => 3, 'inline_query' => ['id' => '1', 'from' => ['id' => 5], 'query' => '']]);
    }

    public function testEditedAlbumMessagesKeepTheirChat(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());
        $collector = new TelegramMediaGroupCollector(new \ChatFlow\Telegram\MediaGroup\InMemoryTelegramMediaGroupStore(), 0);

        $update = $collector->collect([
            'update_id' => 7,
            'edited_message' => [
                'message_id' => 7,
                'media_group_id' => 'g1',
                'chat' => ['id' => 123],
                'from' => ['id' => 456],
                'caption' => 'fixed',
                'photo' => [['file_id' => 'p', 'file_unique_id' => 'u', 'width' => 1, 'height' => 1]],
            ],
        ]);

        self::assertIsArray($update);
        $event = $adapter->createInboundEvent($update);

        self::assertSame('123', $event->getConversationId());
        self::assertSame(456, $event->getUserId());
        self::assertCount(1, $event->getAttachments());
        self::assertSame('fixed', $event->getAttachments()[0]->get('caption'));
    }

    public function testAdapterDeliversCoreEffectsAndDeclaresCapabilities(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = $this->actionContext($adapter);

        self::assertTrue($adapter->deliver($context, new ReplyEffect(View::text('contract reply')))->isSuccess());
        self::assertTrue($adapter->deliver($context, new RenderEffect(View::text('contract render')))->isSuccess());
        self::assertTrue($adapter->deliver($context, new AckEffect('contract ack'))->isSuccess());
        PlatformAdapterContractAssertions::assertAdapterDeclaresSerializableCapabilities($adapter);

        self::assertSame(['sendMessage', 'editMessageText', 'answerCallbackQuery'], array_column($client->getRequests(), 'endpoint'));
    }

    public function testMediaRenderFromMediaMessageUsesEditMessageMedia(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = $this->actionContext($adapter, 'photo');

        self::assertTrue($adapter->deliver(
            $context,
            new RenderEffect(View::text('Updated promo')->addMedia(new MediaAttachment('image', 'https://example.com/promo.jpg'))),
        )->isSuccess());

        $requests = $client->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('editMessageMedia', $requests[0]['endpoint']);
        self::assertSame(123, $requests[0]['params']['chat_id']);
        self::assertSame(10, $requests[0]['params']['message_id']);

        self::assertIsString($requests[0]['params']['media'] ?? null);
        $media = json_decode($requests[0]['params']['media'], true);
        self::assertIsArray($media);
        self::assertSame('photo', $media['type'] ?? null);
        self::assertSame('https://example.com/promo.jpg', $media['media'] ?? null);
        self::assertSame('Updated promo', $media['caption'] ?? null);
    }

    public function testCallbackAckFailureIsBestEffortAndDoesNotBlockRender(): void
    {
        $client = new MockHttpClient();
        $api = new class ('TEST_TOKEN', false, $client) extends Api {
            /**
             * @param array<mixed> $params
             */
            public function answerCallbackQuery(array $params): bool
            {
                throw new RuntimeException('query is too old');
            }
        };
        $adapter = new TelegramPlatformAdapter($api, new FileDownloader($api), $this->encoder);
        $context = $this->actionContext($adapter);

        $ackDelivery = $adapter->deliver($context, new AckEffect('Opening'));
        $renderDelivery = $adapter->deliver($context, new RenderEffect(View::text('Menu opened')));

        self::assertTrue($ackDelivery->isSuccess());
        self::assertSame('telegram_ack_unavailable', $ackDelivery->getMessage());
        self::assertTrue($renderDelivery->isSuccess());
        self::assertSame('editMessageText', $client->getRequests()[0]['endpoint']);
    }

    public function testAttachmentDownloadUsesTelegramFileApi(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = new Context(
            new InboundEvent(
                conversation: new ConversationRef('123', 'telegram'),
                user: new UserRef(456, 'telegram'),
                attachments: [new InboundAttachment(type: 'photo', id: 'telegram_file_1')],
            ),
            $adapter,
            new Container(),
        );
        $destination = sys_get_temp_dir() . '/chatflow-telegram-download-test-' . bin2hex(random_bytes(6));
        $path = null;

        try {
            $path = $adapter->downloadAttachment($context, $destination);

            self::assertIsString($path);
            self::assertFileExists($path);
            self::assertSame('dummy file content', file_get_contents($path));
            self::assertSame('getFile', $client->getRequests()[0]['endpoint']);
        } finally {
            if ($path !== null && is_file($path)) {
                unlink($path);
            }

            if (is_dir($destination . '/mock')) {
                rmdir($destination . '/mock');
            }

            if (is_dir($destination)) {
                rmdir($destination);
            }
        }
    }

    public function testInboundMediaAttachmentsIncludeTelegramMetadata(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $event = $adapter->createInboundEvent([
            'update_id' => 10,
            'message' => [
                'message_id' => 20,
                'caption' => 'Media caption',
                'media_group_id' => 'album-1',
                'chat' => ['id' => 123],
                'from' => ['id' => 456],
                'photo' => [
                    ['file_id' => 'photo_small', 'file_unique_id' => 'pu1', 'width' => 100, 'height' => 100],
                    ['file_id' => 'photo_large', 'file_unique_id' => 'pu2', 'width' => 800, 'height' => 600, 'file_size' => 1000],
                ],
                'document' => [
                    'file_id' => 'doc_1',
                    'file_unique_id' => 'du1',
                    'file_name' => 'brief.pdf',
                    'mime_type' => 'application/pdf',
                    'file_size' => 2000,
                ],
            ],
        ]);

        self::assertCount(2, $event->getAttachments());
        $photo = $event->getAttachments()[0];
        $document = $event->getAttachments()[1];

        self::assertSame('photo', $photo->getType());
        self::assertSame('photo_large', $photo->getId());
        self::assertSame('album-1', $photo->get('media_group_id'));
        self::assertSame('Media caption', $photo->get('caption'));
        self::assertSame(800, $photo->get('width'));
        self::assertSame(600, $photo->get('height'));
        self::assertSame('document', $document->getType());
        self::assertSame('brief.pdf', $document->getName());
        self::assertSame('application/pdf', $document->getMimeType());
        self::assertSame('album-1', $event->getMessageRef()?->get('media_group_id'));
    }

    public function testMediaGroupAggregateMessagesBecomeOrderedAttachments(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $event = $adapter->createInboundEvent([
            'update_id' => 11,
            'message' => [
                'message_id' => 30,
                'media_group_id' => 'album-2',
                'chat' => ['id' => 123],
                'from' => ['id' => 456],
                TelegramMediaGroupCollector::AGGREGATE_KEY => [
                    [
                        'message_id' => 30,
                        'media_group_id' => 'album-2',
                        'caption' => 'First',
                        'photo' => [['file_id' => 'a', 'file_unique_id' => 'a1', 'width' => 10, 'height' => 10]],
                    ],
                    [
                        'message_id' => 31,
                        'media_group_id' => 'album-2',
                        'caption' => 'Second',
                        'video' => ['file_id' => 'v', 'file_unique_id' => 'v1', 'width' => 640, 'height' => 360, 'duration' => 5],
                    ],
                ],
            ],
        ]);

        self::assertCount(2, $event->getAttachments());
        self::assertSame('photo', $event->getAttachments()[0]->getType());
        self::assertSame('First', $event->getAttachments()[0]->get('caption'));
        self::assertSame('video', $event->getAttachments()[1]->getType());
        self::assertSame('Second', $event->getAttachments()[1]->get('caption'));
    }

    public function testTelegramMessageOptionsAreMappedToApiParams(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = new Context(new InboundEvent(conversation: new ConversationRef('123'), user: new UserRef(456)), $adapter, new Container());

        $view = TelegramView::forceReply('Reply with details', replyToMessageId: 99);
        self::assertTrue($adapter->deliver($context, new ReplyEffect($view))->isSuccess());

        $params = $client->getRequests()[0]['params'];
        self::assertSame('HTML', $params['parse_mode'] ?? null);
        self::assertSame(99, $params['reply_to_message_id'] ?? null);
        self::assertIsString($params['reply_markup'] ?? null);
        self::assertSame(['force_reply' => true], json_decode($params['reply_markup'], true));
    }

    public function testCallbackPayloadsAreSignedOrStoredWhenRendered(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = new Context(new InboundEvent(conversation: new ConversationRef('123'), user: new UserRef(456)), $adapter, new Container());
        $longPayload = ['text' => str_repeat('x', 120)];

        $adapter->deliver($context, new ReplyEffect(
            View::text('Buttons')
                ->addActionRow(new Action('cart:add', 'Add', ['id' => 10]))
                ->addActionRow(new Action('admin:approve', 'Approve', $longPayload)),
        ));

        $small = $this->callbackDataAt($client, 0);
        $large = $this->callbackDataAt($client, 1);

        self::assertStringStartsWith('cs:', $small);
        self::assertStringStartsWith('cf:', $large);
        self::assertSame(['admin:approve', $longPayload], [$this->encoder->decode($large)->actionId, $this->encoder->decode($large)->payload]);
    }

    public function testFileCallbackStoreExpiresStalePayloadsLazily(): void
    {
        $directory = $this->temporaryDirectory('chatflow-telegram-callbacks-');
        $store = new FileTelegramCallbackStore($directory, ttlSeconds: 60, cleanupProbability: 0);

        try {
            $token = str_repeat('a', 16);
            $store->put($token, ['id' => 'admin:approve', 'payload' => ['post' => 10]]);
            self::assertSame(['id' => 'admin:approve', 'payload' => ['post' => 10]], $store->get($token));

            $path = $directory . '/' . $token . '.json';
            file_put_contents($path, json_encode(['created_at' => time() - 120, 'payload' => ['id' => 'admin:approve', 'payload' => ['post' => 10]]], JSON_THROW_ON_ERROR));

            $another = new FileTelegramCallbackStore($directory, ttlSeconds: 60);
            self::assertFileExists($path, 'construction does not scan the store');
            self::assertNull($another->get($token), 'reading an expired payload removes it');
            self::assertFileDoesNotExist($path);

            $stale = str_repeat('b', 16);
            $fresh = str_repeat('c', 16);
            $store->put($stale, ['id' => 'admin:reject', 'payload' => ['post' => 11]]);
            $store->put($fresh, ['id' => 'admin:hold', 'payload' => ['post' => 12]]);
            touch($directory . '/' . $stale . '.json', time() - 120);
            $store->cleanupExpired();

            self::assertFileDoesNotExist($directory . '/' . $stale . '.json');
            self::assertSame(['id' => 'admin:hold', 'payload' => ['post' => 12]], $store->get($fresh));

            file_put_contents($directory . '/' . $fresh . '.json', json_encode(['created_at' => time() - 120, 'payload' => ['id' => 'admin:hold', 'payload' => ['post' => 12]]], JSON_THROW_ON_ERROR));
            $store->put($fresh, ['id' => 'admin:hold', 'payload' => ['post' => 12]]);
            self::assertSame(['id' => 'admin:hold', 'payload' => ['post' => 12]], $store->get($fresh), 'rendering the button again restarts its TTL');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testFileMediaGroupStoreCleansUpStaleGroups(): void
    {
        $directory = $this->temporaryDirectory('chatflow-telegram-media-groups-');
        $store = new FileTelegramMediaGroupStore($directory, ttlSeconds: 1, cleanupProbability: 0);

        try {
            $store->storePart('123:album', 10, ['update_id' => 1, 'message' => ['message_id' => 10]]);
            self::assertCount(1, $store->getParts('123:album'));

            touch($directory . '/123_album/10.json', time() - 10);
            $store->cleanupExpired();

            self::assertSame([], $store->getParts('123:album'));
            self::assertDirectoryDoesNotExist($directory . '/123_album');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testPublisherSendsEditsDeletesAndReturnsTypedResults(): void
    {
        $client = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $client);
        $publisher = new TelegramPublisher($api);
        $tmpFile = tempnam(sys_get_temp_dir(), 'chatflow-telegram-media-');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, 'image');

        try {
            $message = $publisher->sendMessage('123', 'Published');
            $single = $publisher->sendMedia('123', new TelegramMedia('photo', TelegramMediaSource::localPath($tmpFile), 'Local'));
            $group = $publisher->sendMediaGroup('123', [
                new TelegramMedia('photo', TelegramMediaSource::url('https://example.com/a.jpg'), 'A'),
                new TelegramMedia('photo', TelegramMediaSource::fileId('file_id_12345678901234567890'), 'B'),
            ]);
            $edit = $publisher->editText('123', 77, 'Edited');
            $caption = $publisher->editCaption('123', 78, 'Edited caption');
            $delete = $publisher->deleteMessage('123', 79);
        } finally {
            @unlink($tmpFile);
        }

        self::assertSame('sendMessage', $message->getEndpoint());
        self::assertSame('sendPhoto', $single->getEndpoint());
        self::assertSame('sendMediaGroup', $group->getEndpoint());
        self::assertSame('editMessageText', $edit->getEndpoint());
        self::assertSame('editMessageCaption', $caption->getEndpoint());
        self::assertSame('deleteMessage', $delete->getEndpoint());
        self::assertSame(
            ['sendMessage', 'sendPhoto', 'sendMediaGroup', 'editMessageText', 'editMessageCaption', 'deleteMessage'],
            array_column($client->getRequests(), 'endpoint'),
        );
        self::assertSame('[BINARY DATA]', $client->getRequests()[1]['params']['photo'] ?? null);
    }

    public function testMediaDeliveryFailureReturnsDeliveryError(): void
    {
        $client = new MockHttpClient();
        $client->failEndpoint('sendPhoto', 'Bad Request: wrong type of the web page content');
        $adapter = $this->createAdapter($client);
        $context = new Context(new InboundEvent(conversation: new ConversationRef('123'), user: new UserRef(456)), $adapter, new Container());

        $delivery = $adapter->deliver(
            $context,
            new ReplyEffect(View::text('Photo')->addMedia(new MediaAttachment('image', 'https://example.com/not-image'))),
        );

        self::assertTrue($delivery->isError());
        self::assertSame('telegram_delivery_failed', $delivery->getMessage());
    }

    public function testUnansweredCallbackQueriesAreAcknowledgedAfterHandling(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = $this->actionContext($adapter);

        $adapter->afterHandle($context, Result::noMatch());
        $adapter->afterHandle($context, Result::noMatch());

        self::assertSame(['answerCallbackQuery'], array_column($client->getRequests(), 'endpoint'), 'answered exactly once');
        self::assertArrayNotHasKey('text', $client->getRequests()[0]['params']);

        $acked = new MockHttpClient();
        $adapter = $this->createAdapter($acked);
        $context = $this->actionContext($adapter);
        $adapter->deliver($context, new AckEffect('Saved'));
        $adapter->afterHandle($context, Result::success());

        self::assertSame(['answerCallbackQuery'], array_column($acked->getRequests(), 'endpoint'), 'an explicit ack is not repeated');

        $plain = new MockHttpClient();
        $adapter = $this->createAdapter($plain);
        $adapter->afterHandle(new Context(new InboundEvent(conversation: new ConversationRef('123')), $adapter, new Container()), Result::success());

        self::assertSame([], $plain->getRequests(), 'text messages have nothing to acknowledge');
    }

    public function testUnchangedScreensAreNotDeletedAndResent(): void
    {
        $client = new MockHttpClient();
        $client->failEndpoint('editMessageText', 'Bad Request: message is not modified');
        $adapter = $this->createAdapter($client);

        $delivery = $adapter->deliver($this->actionContext($adapter), new RenderEffect(View::text('Menu opened')));

        self::assertTrue($delivery->isSuccess());
        self::assertSame(['editMessageText'], array_column($client->getRequests(), 'endpoint'));
        self::assertSame(['ok' => true, 'not_modified' => true], $delivery->getData()['telegram'] ?? null);
    }

    public function testRenderFallbackFailuresAreLoggedWithoutBreakingDelivery(): void
    {
        $client = new MockHttpClient();
        $client->failEndpoint('editMessageText', "Bad Request: message can't be edited");
        $logger = new class extends AbstractLogger {
            /**
             * @var list<array{level: string, message: string, context: array<mixed>}>
             */
            public array $records = [];

            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => \is_string($level) ? $level : 'unknown', 'message' => (string) $message, 'context' => $context];
            }
        };
        $api = new Api('TEST_TOKEN', false, $client);
        $adapter = new TelegramPlatformAdapter($api, new FileDownloader($api), $this->encoder, logger: $logger);

        $delivery = $adapter->deliver($this->actionContext($adapter), new RenderEffect(View::text('Menu opened')));

        self::assertTrue($delivery->isSuccess());
        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], array_column($client->getRequests(), 'endpoint'));
        self::assertSame('notice', $logger->records[0]['level'] ?? null);
        self::assertSame('editMessageText', $logger->records[0]['context']['endpoint'] ?? null);
    }

    private function callbackDataAt(MockHttpClient $client, int $row): string
    {
        $replyMarkup = $client->getRequests()[0]['params']['reply_markup'] ?? null;
        self::assertIsString($replyMarkup);
        $markup = json_decode($replyMarkup, true);
        self::assertIsArray($markup);
        $keyboard = $markup['inline_keyboard'] ?? null;
        self::assertIsArray($keyboard);
        $buttons = $keyboard[$row] ?? null;
        self::assertIsArray($buttons);
        $button = $buttons[0] ?? null;
        self::assertIsArray($button);
        $data = $button['callback_data'] ?? null;
        self::assertIsString($data);

        return $data;
    }

    public function testDeliveryTargetsTheChatEvenWhenTheConversationHasItsOwnId(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = new Context(
            new InboundEvent(
                conversation: new ConversationRef('-100500:777', 'telegram', ['chat' => ['id' => -100500, 'type' => 'supergroup']]),
                user: new UserRef(777, 'telegram'),
                text: 'hello',
            ),
            $adapter,
            new Container(),
        );

        self::assertSame('-100500', TelegramPlatformAdapter::chatIdFor($context));
        self::assertTrue($adapter->deliver($context, new ReplyEffect(View::text('scoped reply')))->isSuccess());
        self::assertTrue($adapter->deliver($context, new ReplyEffect(
            View::text('scoped photo')->addMedia(new MediaAttachment('image', 'https://example.com/photo.jpg')),
        ))->isSuccess());

        self::assertSame(['sendMessage', 'sendPhoto'], array_column($client->getRequests(), 'endpoint'));
        self::assertSame('-100500', $client->getRequests()[0]['params']['chat_id'] ?? null);
        self::assertSame('-100500', $client->getRequests()[1]['params']['chat_id'] ?? null);
    }

    public function testTheConversationIdIsTheDeliveryTargetWhenTheChatIsUnknown(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());
        $context = new Context(
            new InboundEvent(conversation: new ConversationRef('123', 'telegram'), text: 'hello'),
            $adapter,
            new Container(),
        );

        self::assertSame('123', TelegramPlatformAdapter::chatIdFor($context));
    }

    public function testALongReplyIsSentAsSeveralMessagesWithTheKeyboardOnTheLast(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $view = View::text(str_repeat("line\n", 2000))->addActionRow(new Action('menu:open', 'Menu'));

        self::assertTrue($adapter->deliver($this->plainContext($adapter), new ReplyEffect($view))->isSuccess());

        $requests = $client->getRequests();
        $last = \count($requests) - 1;

        self::assertGreaterThan(1, \count($requests));

        foreach ($requests as $index => $request) {
            self::assertSame('sendMessage', $request['endpoint']);
            self::assertLessThanOrEqual(4096, mb_strlen(self::paramString($request, 'text')));
            self::assertSame(
                $index === $last,
                isset($request['params']['reply_markup']),
                'Only the last message carries the keyboard.',
            );
        }
    }

    public function testALongCaptionContinuesAsFollowUpMessages(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $view = View::text(str_repeat('caption ', 300))->addMedia(new MediaAttachment('image', 'https://example.com/photo.jpg'));

        self::assertTrue($adapter->deliver($this->plainContext($adapter), new ReplyEffect($view))->isSuccess());

        $requests = $client->getRequests();

        self::assertSame(['sendPhoto', 'sendMessage'], array_column($requests, 'endpoint'));
        self::assertLessThanOrEqual(1024, mb_strlen(self::paramString($requests[0], 'caption')));
        self::assertNotSame('', self::paramString($requests[1], 'text'));
    }

    public function testARenderTooLongToEditFallsBackToSendingChunks(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);

        $adapter->deliver($this->actionContext($adapter), new RenderEffect(View::text(str_repeat("line\n", 2000))));

        $endpoints = array_column($client->getRequests(), 'endpoint');

        self::assertNotContains('editMessageText', $endpoints, 'A text over the limit cannot be edited into place.');
        self::assertSame('deleteMessage', $endpoints[0]);
        self::assertGreaterThan(1, \count(array_filter($endpoints, static fn(string $endpoint): bool => $endpoint === 'sendMessage')));
    }

    public function testAnOversizedAckIsTruncatedToWhatTelegramAccepts(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);

        $adapter->deliver($this->actionContext($adapter), new AckEffect(str_repeat('e', 500)));

        $requests = $client->getRequests();

        self::assertSame('answerCallbackQuery', $requests[0]['endpoint']);
        self::assertSame(200, mb_strlen(self::paramString($requests[0], 'text')));
    }

    /**
     * @param array{endpoint: string, method: string, params: array<string, mixed>} $request
     */
    private static function paramString(array $request, string $key): string
    {
        $value = $request['params'][$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    private function plainContext(TelegramPlatformAdapter $adapter): Context
    {
        return new Context(
            new InboundEvent(conversation: new ConversationRef('123', 'telegram'), text: 'hi'),
            $adapter,
            new Container(),
        );
    }

    private function createAdapter(MockHttpClient $client): TelegramPlatformAdapter
    {
        $api = new Api('TEST_TOKEN', false, $client);

        return new TelegramPlatformAdapter($api, new FileDownloader($api), $this->encoder);
    }

    private function actionContext(TelegramPlatformAdapter $adapter, string $messageType = 'text'): Context
    {
        return new Context(
            new InboundEvent(
                conversation: new ConversationRef('123', 'telegram'),
                user: new UserRef(456, 'telegram'),
                actionId: 'menu:open',
                messageRef: new MessageRef('10', null, 'cb-1', [
                    'chat_id' => 123,
                    'message_id' => 10,
                    'callback_query_id' => 'cb-1',
                    'message_type' => $messageType,
                ]),
            ),
            $adapter,
            new Container(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function callbackUpdate(string $data): array
    {
        return [
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => 456],
                'data' => $data,
                'message' => ['message_id' => 11, 'chat' => ['id' => 123], 'text' => 'Menu'],
            ],
        ];
    }

    private function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($directory, 0o755, true);

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $paths = glob($directory . '/*');

        foreach ($paths === false ? [] : $paths as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
