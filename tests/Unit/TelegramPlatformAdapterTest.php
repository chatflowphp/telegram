<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Container\Container;
use ChatFlow\Core\Context;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Event\MessageRef;
use ChatFlow\Event\UserRef;
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
use ChatFlow\Telegram\TelegramPlatformAdapter;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\Telegram\TelegramView;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Tests\Contracts\PlatformAdapterContractAssertions;
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Telegram\Bot\Api;

final class TelegramPlatformAdapterTest extends TestCase
{
    public function test_message_update_is_normalized_to_typed_inbound_event(): void
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

        self::assertSame('123', $event->getConversationId());
        self::assertSame(456, $event->getUserId());
        self::assertSame('/start', $event->getText());
        self::assertSame('10', $event->getMessageRef()?->getId());
        PlatformAdapterContractAssertions::assertInboundEventSatisfiesCoreContract($event);
    }

    public function test_callback_query_is_normalized_to_action_event(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $event = $adapter->createInboundEvent([
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => 456],
                'data' => '{"id":"menu:open","payload":{"page":1}}',
                'message' => [
                    'message_id' => 11,
                    'chat' => ['id' => 123],
                    'text' => 'Menu',
                ],
            ],
        ]);

        self::assertTrue($event->isAction());
        self::assertSame('menu:open', $event->getActionId());
        self::assertSame(['page' => 1], $event->getActionPayload());
        self::assertSame('cb-1', $event->getMessageRef()?->getReplyToken());
        PlatformAdapterContractAssertions::assertInboundEventSatisfiesCoreContract($event);
    }

    public function test_adapter_delivers_core_effects_and_declares_capabilities(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = new Context(
            new InboundEvent(
                conversation: new ConversationRef('123', 'telegram'),
                user: new UserRef(456, 'telegram'),
                actionId: 'menu:open',
                messageRef: new MessageRef('10', null, 'cb-1', [
                    'chat_id' => 123,
                    'message_id' => 10,
                    'callback_query_id' => 'cb-1',
                    'message_type' => 'text',
                ]),
            ),
            $adapter,
            $this->createContainer(),
        );

        self::assertTrue($adapter->deliver($context, new ReplyEffect(View::text('contract reply')))->isSuccess());
        self::assertTrue($adapter->deliver($context, new RenderEffect(View::text('contract render')))->isSuccess());
        self::assertTrue($adapter->deliver($context, new AckEffect('contract ack'))->isSuccess());
        PlatformAdapterContractAssertions::assertAdapterDeclaresSerializableCapabilities($adapter);

        $endpoints = array_column($client->getRequests(), 'endpoint');
        self::assertContains('sendMessage', $endpoints);
        self::assertContains('editMessageText', $endpoints);
        self::assertContains('answerCallbackQuery', $endpoints);
    }

    public function test_media_render_from_media_message_uses_edit_message_media(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = new Context(
            new InboundEvent(
                conversation: new ConversationRef('123', 'telegram'),
                user: new UserRef(456, 'telegram'),
                actionId: 'promo:next',
                messageRef: new MessageRef('10', null, 'cb-1', [
                    'chat_id' => 123,
                    'message_id' => 10,
                    'callback_query_id' => 'cb-1',
                    'message_type' => 'photo',
                ]),
            ),
            $adapter,
            $this->createContainer(),
        );

        self::assertTrue($adapter->deliver(
            $context,
            new RenderEffect(View::text('Updated promo')->addMedia(new MediaAttachment('image', 'https://example.com/promo.jpg')))
        )->isSuccess());

        $requests = $client->getRequests();
        self::assertCount(1, $requests);
        self::assertSame('editMessageMedia', $requests[0]['endpoint']);
        self::assertSame(123, $requests[0]['params']['chat_id']);
        self::assertSame(10, $requests[0]['params']['message_id']);

        $media = json_decode((string) $requests[0]['params']['media'], true);
        self::assertSame('photo', $media['type'] ?? null);
        self::assertSame('https://example.com/promo.jpg', $media['media'] ?? null);
        self::assertSame('Updated promo', $media['caption'] ?? null);
    }

    public function test_callback_ack_failure_is_best_effort_and_does_not_block_render(): void
    {
        $client = new MockHttpClient();
        $api = new class ('TEST_TOKEN', false, $client) extends Api {
            public function answerCallbackQuery(array $params): bool
            {
                throw new RuntimeException('query is too old');
            }
        };
        $adapter = new TelegramPlatformAdapter($api, new FileDownloader($api));
        $context = new Context(
            new InboundEvent(
                conversation: new ConversationRef('123', 'telegram'),
                user: new UserRef(456, 'telegram'),
                actionId: 'menu:open',
                messageRef: new MessageRef('10', null, 'stale-callback', [
                    'chat_id' => 123,
                    'message_id' => 10,
                    'callback_query_id' => 'stale-callback',
                    'message_type' => 'text',
                ]),
            ),
            $adapter,
            $this->createContainer(),
        );

        $ackDelivery = $adapter->deliver($context, new AckEffect('Opening'));
        $renderDelivery = $adapter->deliver($context, new RenderEffect(View::text('Menu opened')));

        self::assertTrue($ackDelivery->isSuccess());
        self::assertSame('telegram_ack_unavailable', $ackDelivery->getMessage());
        self::assertTrue($renderDelivery->isSuccess());
        self::assertSame('editMessageText', $client->getRequests()[0]['endpoint']);
    }

    public function test_attachment_download_uses_telegram_file_api(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = new Context(
            new InboundEvent(
                conversation: new ConversationRef('123', 'telegram'),
                user: new UserRef(456, 'telegram'),
                attachments: [
                    new InboundAttachment(type: 'photo', id: 'telegram_file_1'),
                ],
            ),
            $adapter,
            $this->createContainer(),
        );
        $destination = sys_get_temp_dir() . '/chatflow-telegram-download-test-' . bin2hex(random_bytes(6));

        try {
            $path = $adapter->downloadAttachment($context, $destination);

            self::assertIsString($path);
            self::assertFileExists($path);
            self::assertSame('dummy file content', file_get_contents($path));
            self::assertSame('getFile', $client->getRequests()[0]['endpoint']);
        } finally {
            if (isset($path) && is_file($path)) {
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

    public function test_inbound_media_attachments_include_telegram_metadata(): void
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

    public function test_media_group_aggregate_messages_become_ordered_attachments(): void
    {
        $adapter = $this->createAdapter(new MockHttpClient());

        $event = $adapter->createInboundEvent([
            'update_id' => 11,
            'message' => [
                'message_id' => 30,
                'media_group_id' => 'album-2',
                'chat' => ['id' => 123],
                'from' => ['id' => 456],
                '__chatflow_media_group_messages' => [
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
                        'video' => [
                            'file_id' => 'v',
                            'file_unique_id' => 'v1',
                            'width' => 640,
                            'height' => 360,
                            'duration' => 5,
                        ],
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

    public function test_telegram_message_options_are_mapped_to_api_params(): void
    {
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client);
        $context = new Context(
            new InboundEvent(conversation: new ConversationRef('123'), user: new UserRef(456)),
            $adapter,
            $this->createContainer(),
        );

        $view = TelegramView::forceReply('Reply with details', replyToMessageId: 99);
        self::assertTrue($adapter->deliver($context, new ReplyEffect($view))->isSuccess());

        $params = $client->getRequests()[0]['params'];
        self::assertSame('HTML', $params['parse_mode'] ?? null);
        self::assertSame(99, $params['reply_to_message_id'] ?? null);
        $markup = json_decode((string) ($params['reply_markup'] ?? ''), true);
        self::assertSame(['force_reply' => true], $markup);
    }

    public function test_callback_payload_encoder_stores_payloads_over_telegram_limit(): void
    {
        $store = new InMemoryTelegramCallbackStore();
        $encoder = new TelegramCallbackPayloadEncoder($store);
        $client = new MockHttpClient();
        $adapter = $this->createAdapter($client, $encoder);
        $context = new Context(
            new InboundEvent(conversation: new ConversationRef('123'), user: new UserRef(456)),
            $adapter,
            $this->createContainer(),
        );
        $longPayload = ['text' => str_repeat('x', 120)];

        $adapter->deliver(
            $context,
            new ReplyEffect(View::text('Long callback')->addActionRow(new \ChatFlow\View\Action('admin:approve', 'Approve', $longPayload)))
        );

        $replyMarkup = (string) ($client->getRequests()[0]['params']['reply_markup'] ?? '');
        $markup = json_decode($replyMarkup, true);
        $callbackData = $markup['inline_keyboard'][0][0]['callback_data'] ?? null;

        self::assertIsString($callbackData);
        self::assertStringStartsWith('cf:', $callbackData);
        self::assertSame(['admin:approve', $longPayload], $encoder->decode($callbackData));
    }

    public function test_file_callback_store_expires_stale_payloads(): void
    {
        $directory = $this->temporaryDirectory('chatflow-telegram-callbacks-');
        $store = new FileTelegramCallbackStore($directory, ttlSeconds: 1, cleanupProbability: 0);

        try {
            $token = $store->put(['id' => 'admin:approve', 'payload' => ['post' => 10]]);
            self::assertSame(['id' => 'admin:approve', 'payload' => ['post' => 10]], $store->get($token));

            touch($directory . '/' . $token . '.json', time() - 10);
            $store->cleanupExpired();

            self::assertNull($store->get($token));
            self::assertFileDoesNotExist($directory . '/' . $token . '.json');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_file_media_group_store_cleans_up_stale_groups(): void
    {
        $directory = $this->temporaryDirectory('chatflow-telegram-media-groups-');
        $store = new FileTelegramMediaGroupStore($directory, ttlSeconds: 1, cleanupProbability: 0);

        try {
            $store->storePart('123:album', 10, [
                'update_id' => 1,
                'message' => ['message_id' => 10],
            ]);
            self::assertCount(1, $store->getParts('123:album'));

            touch($directory . '/123_album/10.json', time() - 10);
            $store->cleanupExpired();

            self::assertSame([], $store->getParts('123:album'));
            self::assertDirectoryDoesNotExist($directory . '/123_album');
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_publisher_sends_edits_deletes_and_returns_typed_results(): void
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
            array_column($client->getRequests(), 'endpoint')
        );
        self::assertSame('[BINARY DATA]', $client->getRequests()[1]['params']['photo'] ?? null);
    }

    public function test_media_delivery_failure_returns_delivery_error(): void
    {
        $client = new MockHttpClient();
        $client->failEndpoint('sendPhoto', 'Bad Request: wrong type of the web page content');
        $adapter = $this->createAdapter($client);
        $context = new Context(
            new InboundEvent(conversation: new ConversationRef('123'), user: new UserRef(456)),
            $adapter,
            $this->createContainer(),
        );

        $delivery = $adapter->deliver(
            $context,
            new ReplyEffect(View::text('Photo')->addMedia(new MediaAttachment('image', 'https://example.com/not-image')))
        );

        self::assertTrue($delivery->isError());
        self::assertSame('telegram_delivery_failed', $delivery->getMessage());
    }

    public function test_render_fallback_failures_are_logged_without_breaking_delivery(): void
    {
        $client = new MockHttpClient();
        $client->failEndpoint('editMessageText', 'Bad Request: message is not modified');
        $logger = new class () extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, Stringable|string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        $api = new Api('TEST_TOKEN', false, $client);
        $adapter = new TelegramPlatformAdapter($api, new FileDownloader($api), logger: $logger);
        $context = new Context(
            new InboundEvent(
                conversation: new ConversationRef('123', 'telegram'),
                user: new UserRef(456, 'telegram'),
                actionId: 'menu:open',
                messageRef: new MessageRef('10', null, 'cb-1', [
                    'chat_id' => 123,
                    'message_id' => 10,
                    'callback_query_id' => 'cb-1',
                    'message_type' => 'text',
                ]),
            ),
            $adapter,
            $this->createContainer(),
        );

        $delivery = $adapter->deliver($context, new RenderEffect(View::text('Menu opened')));

        self::assertTrue($delivery->isSuccess());
        self::assertSame(['editMessageText', 'deleteMessage', 'sendMessage'], array_column($client->getRequests(), 'endpoint'));
        self::assertSame('notice', $logger->records[0]['level'] ?? null);
        self::assertSame('editMessageText', $logger->records[0]['context']['endpoint'] ?? null);
    }

    private function createAdapter(
        MockHttpClient $client,
        ?TelegramCallbackPayloadEncoder $callbackPayloadEncoder = null,
    ): TelegramPlatformAdapter {
        $api = new Api('TEST_TOKEN', false, $client);

        return new TelegramPlatformAdapter($api, new FileDownloader($api), $callbackPayloadEncoder);
    }

    private function createContainer(): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);

        return new Container($builder, autowire: true, useAttributes: false);
    }

    private function temporaryDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($directory, 0755, true);

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
