<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Contracts\RuntimeDependencyBinderInterface;
use ChatFlow\Core\Context;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Event\MessageRef;
use ChatFlow\Event\UserRef;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Outbound\AckEffect;
use ChatFlow\Outbound\DeliveryResult;
use ChatFlow\Outbound\OutboundEffectInterface;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\Platform\PlatformCapabilities;
use ChatFlow\Telegram\Callback\InMemoryTelegramCallbackStore;
use ChatFlow\Telegram\Callback\TelegramCallbackPayloadEncoder;
use ChatFlow\Telegram\UI\TelegramScreenManager;
use ChatFlow\View\Action;
use ChatFlow\View\Choice;
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;
use Throwable;

final class TelegramPlatformAdapter implements PlatformAdapterInterface, RuntimeDependencyBinderInterface
{
    public function __construct(
        private readonly Api $api,
        private readonly FileDownloader $fileDownloader,
        private readonly ?TelegramCallbackPayloadEncoder $callbackPayloadEncoder = null,
        private readonly ?TelegramScreenManager $screenManager = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    private readonly LoggerInterface $logger;

    public function bindRuntimeDependencies(ContainerInterface $container, Context $context): void
    {
        $container->set(TelegramContext::class, new TelegramContext($context));
        if ($this->screenManager !== null) {
            $container->set(TelegramScreenManager::class, $this->screenManager->forContext($context));
        }
    }

    public function createInboundEvent(mixed $input): InboundEventInterface
    {
        $update = $input instanceof Update ? $input : new Update(is_array($input) ? $input : []);
        $raw = $update->toArray();

        $message = $raw['message'] ?? $raw['edited_message'] ?? null;
        $callbackQuery = $raw['callback_query'] ?? null;
        $telegramEvent = $raw['my_chat_member'] ?? $raw['chat_member'] ?? null;
        $callbackMessage = is_array($callbackQuery) ? ($callbackQuery['message'] ?? null) : null;
        $sourceMessage = is_array($message)
            ? $message
            : (is_array($callbackMessage) ? $callbackMessage : (is_array($telegramEvent) ? $telegramEvent : []));

        $chat = is_array($sourceMessage['chat'] ?? null) ? $sourceMessage['chat'] : [];
        $from = is_array($sourceMessage['from'] ?? null) ? $sourceMessage['from'] : [];
        if ($from === [] && is_array($sourceMessage['new_chat_member']['user'] ?? null)) {
            $from = $sourceMessage['new_chat_member']['user'];
        }
        $from = $from !== []
            ? $from
            : (is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : []);

        $conversationId = isset($chat['id']) ? (string) $chat['id'] : '0';
        $userId = $from['id'] ?? null;
        $text = (string) ($sourceMessage['text'] ?? $sourceMessage['caption'] ?? '');

        $actionId = null;
        $actionPayload = null;
        if (is_array($callbackQuery) && isset($callbackQuery['data']) && is_string($callbackQuery['data'])) {
            [$actionId, $actionPayload] = $this->decodeCallbackData($callbackQuery['data']);
        }

        $attachments = $this->extractAttachments($sourceMessage);
        $messageRefData = [
            'chat_id' => $chat['id'] ?? null,
            'message_id' => $sourceMessage['message_id'] ?? null,
            'callback_query_id' => $callbackQuery['id'] ?? null,
            'media_group_id' => $sourceMessage['media_group_id'] ?? null,
            'message_type' => $this->detectMessageType($sourceMessage),
            'update_type' => $this->detectUpdateType($raw),
            'is_action' => $actionId !== null,
        ];
        $messageId = isset($sourceMessage['message_id']) && is_scalar($sourceMessage['message_id'])
            ? (string) $sourceMessage['message_id']
            : null;
        $replyToken = isset($callbackQuery['id']) && is_string($callbackQuery['id']) ? $callbackQuery['id'] : null;

        return new InboundEvent(
            conversation: new ConversationRef($conversationId, 'telegram', ['chat' => $chat]),
            user: is_scalar($userId) ? new UserRef($userId, 'telegram', ['user' => $from]) : null,
            text: $text,
            actionId: $actionId,
            actionPayload: $actionPayload,
            attachments: $attachments,
            messageRef: new MessageRef($messageId, null, $replyToken, $messageRefData),
            metadata: [
                'update_id' => $raw['update_id'] ?? null,
                'telegram' => $raw,
                'user' => $from,
                'chat' => $chat,
            ],
        );
    }

    public function deliver(Context $context, OutboundEffectInterface $effect): DeliveryResult
    {
        try {
            if ($effect instanceof ReplyEffect) {
                $response = $this->sendView($context, $effect->getView(), false);

                return DeliveryResult::success('telegram_reply_delivered', [
                    'telegram' => $this->normalizeResponse($response),
                ]);
            }

            if ($effect instanceof RenderEffect) {
                $response = $this->sendView($context, $effect->getView(), true);

                return DeliveryResult::success('telegram_render_delivered', [
                    'telegram' => $this->normalizeResponse($response),
                ]);
            }

            if ($effect instanceof AckEffect) {
                $message = $this->deliverAck($context, $effect);

                return DeliveryResult::success($message);
            }

        } catch (Throwable $exception) {
            return DeliveryResult::error('telegram_delivery_failed', [
                'reason' => $exception->getMessage(),
                'effect' => $effect->getType(),
            ]);
        }

        return DeliveryResult::error('Unsupported Telegram outbound effect.');
    }

    public function downloadAttachment(Context $context, string $destinationDir): ?string
    {
        $attachment = $context->getFirstAttachment();
        $fileId = $attachment?->getId() ?? $attachment?->get('file_id');

        if (!is_string($fileId) || $fileId === '') {
            return null;
        }

        return $this->fileDownloader->download($fileId, $destinationDir);
    }

    public function capabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            actions: true,
            choices: true,
            media: true,
            screenRender: true,
            ack: true,
            attachmentDownload: true,
            extensions: ['platform' => 'telegram'],
        );
    }

    /**
     * @return array{0: ?string, 1: mixed}
     */
    private function decodeCallbackData(string $callbackData): array
    {
        return $this->callbackEncoder()->decode($callbackData);
    }

    private function callbackEncoder(): TelegramCallbackPayloadEncoder
    {
        return $this->callbackPayloadEncoder
            ?? new TelegramCallbackPayloadEncoder(new InMemoryTelegramCallbackStore());
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return list<InboundAttachment>
     */
    private function extractAttachments(array $message): array
    {
        $mediaGroupMessages = $message['__chatflow_media_group_messages'] ?? null;
        if (is_array($mediaGroupMessages) && $mediaGroupMessages !== []) {
            $attachments = [];
            foreach ($mediaGroupMessages as $part) {
                if (is_array($part)) {
                    $attachments = [...$attachments, ...$this->extractAttachments($part)];
                }
            }

            return $attachments;
        }

        $attachments = [];
        $commonMeta = $this->commonAttachmentMeta($message);
        $photo = $message['photo'] ?? null;
        if (is_array($photo) && $photo !== []) {
            $bestPhoto = end($photo);
            if (is_array($bestPhoto) && isset($bestPhoto['file_id']) && is_string($bestPhoto['file_id'])) {
                $attachments[] = new InboundAttachment(
                    type: 'photo',
                    id: $bestPhoto['file_id'],
                    size: isset($bestPhoto['file_size']) && is_int($bestPhoto['file_size']) ? $bestPhoto['file_size'] : null,
                    meta: array_merge($commonMeta, $this->filterSerializable([
                        'file_id' => $bestPhoto['file_id'],
                        'file_unique_id' => $bestPhoto['file_unique_id'] ?? null,
                        'width' => $bestPhoto['width'] ?? null,
                        'height' => $bestPhoto['height'] ?? null,
                        'sizes' => $photo,
                    ])),
                );
            }
        }

        foreach (['document', 'video', 'audio', 'voice', 'animation', 'sticker', 'video_note'] as $type) {
            $attachment = $message[$type] ?? null;
            if (is_array($attachment) && isset($attachment['file_id']) && is_string($attachment['file_id'])) {
                $meta = array_merge($commonMeta, $this->filterSerializable([
                    'file_id' => $attachment['file_id'],
                    'file_unique_id' => $attachment['file_unique_id'] ?? null,
                    'width' => $attachment['width'] ?? null,
                    'height' => $attachment['height'] ?? null,
                    'duration' => $attachment['duration'] ?? null,
                    'supports_streaming' => $attachment['supports_streaming'] ?? null,
                    'thumb' => $attachment['thumb'] ?? ($attachment['thumbnail'] ?? null),
                ]));

                $attachments[] = new InboundAttachment(
                    type: $type,
                    id: $attachment['file_id'],
                    name: isset($attachment['file_name']) && is_string($attachment['file_name']) ? $attachment['file_name'] : null,
                    mimeType: isset($attachment['mime_type']) && is_string($attachment['mime_type']) ? $attachment['mime_type'] : null,
                    size: isset($attachment['file_size']) && is_int($attachment['file_size']) ? $attachment['file_size'] : null,
                    meta: $meta,
                );
            }
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function commonAttachmentMeta(array $message): array
    {
        return $this->filterSerializable([
            'caption' => $message['caption'] ?? null,
            'media_group_id' => $message['media_group_id'] ?? null,
            'message_id' => $message['message_id'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function filterSerializable(array $data): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = $this->filterSerializableListOrMap($value);
                continue;
            }

            if (is_scalar($value)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return array<mixed, mixed>
     */
    private function filterSerializableListOrMap(array $data): array
    {
        $filtered = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = $this->filterSerializableListOrMap($value);
                continue;
            }

            if (is_scalar($value)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function detectMessageType(array $message): string
    {
        foreach (['photo', 'document', 'video', 'audio', 'voice', 'animation', 'sticker', 'video_note'] as $type) {
            if (isset($message[$type])) {
                return $type;
            }
        }

        return 'text';
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function detectUpdateType(array $raw): string
    {
        foreach (['message', 'edited_message', 'callback_query', 'my_chat_member', 'chat_member'] as $type) {
            if (isset($raw[$type])) {
                return $type;
            }
        }

        return 'unknown';
    }

    private function sendView(Context $context, View $view, bool $preferRender): mixed
    {
        $messageRef = $context->getMessageRef();
        $messageRefData = $messageRef?->getPlatformData() ?? [];
        $replyMarkup = $this->buildReplyMarkup($view);
        $optionsParams = $this->extractTelegramOptions($view)->toTelegramParams();
        $hasMedia = $view->getMedia() !== [];
        $usesChoices = $view->getChoices() !== [];
        $currentMessageType = $messageRefData['message_type'] ?? 'text';

        if (
            $preferRender
            && $context->isAction()
            && !$hasMedia
            && !$usesChoices
            && ($currentMessageType === 'text')
            && isset($messageRefData['chat_id'], $messageRefData['message_id'])
        ) {
            try {
                return $this->api->editMessageText(array_merge([
                    'chat_id' => $messageRefData['chat_id'],
                    'message_id' => $messageRefData['message_id'],
                    'text' => $view->getText(),
                ], $replyMarkup, $optionsParams));
            } catch (Throwable $exception) {
                $this->logRenderFallback('editMessageText', $context, $exception);
            }
        }

        if (
            $preferRender
            && $context->isAction()
            && $hasMedia
            && !$usesChoices
            && $this->isEditableMediaMessageType((string) $currentMessageType)
            && isset($messageRefData['chat_id'], $messageRefData['message_id'])
        ) {
            try {
                return $this->api->editMessageMedia(array_merge([
                    'chat_id' => $messageRefData['chat_id'],
                    'message_id' => $messageRefData['message_id'],
                    'media' => json_encode(
                        $this->buildInputMedia($view->getMedia()[0], $view->getText(), $this->extractTelegramOptions($view)),
                        JSON_THROW_ON_ERROR
                    ),
                ], $replyMarkup, $optionsParams));
            } catch (Throwable $exception) {
                $this->logRenderFallback('editMessageMedia', $context, $exception);
            }
        }

        if ($preferRender && $context->isAction() && isset($messageRefData['chat_id'], $messageRefData['message_id'])) {
            try {
                $this->api->deleteMessage([
                    'chat_id' => $messageRefData['chat_id'],
                    'message_id' => $messageRefData['message_id'],
                ]);
            } catch (Throwable $exception) {
                $this->logRenderFallback('deleteMessage', $context, $exception);
            }
        }

        if ($hasMedia) {
            $media = $view->getMedia()[0];

            return $this->sendMedia($context, $media, $view->getText(), array_merge($replyMarkup, $optionsParams));
        }

        return $this->api->sendMessage(array_merge([
            'chat_id' => $context->getConversationId(),
            'text' => $view->getText(),
        ], $replyMarkup, $optionsParams));
    }

    private function extractTelegramOptions(View $view): TelegramMessageOptions
    {
        $telegramMeta = $view->getMeta()['telegram'] ?? [];

        return is_array($telegramMeta)
            ? TelegramMessageOptions::fromMeta($telegramMeta)
            : new TelegramMessageOptions();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReplyMarkup(View $view): array
    {
        if ($view->getActions() !== []) {
            $rows = array_map(
                fn (array $row): array => array_map(fn (Action $action): array => $this->mapAction($action), $row),
                $view->getActions()
            );

            return ['reply_markup' => json_encode(['inline_keyboard' => $rows], JSON_THROW_ON_ERROR)];
        }

        if ($view->getChoices() !== []) {
            $rows = array_map(
                fn (array $row): array => array_map(fn (Choice $choice): array => [
                    'text' => $choice->getValue() !== '' ? $choice->getValue() : $choice->getLabel(),
                ], $row),
                $view->getChoices()
            );

            return ['reply_markup' => json_encode([
                'keyboard' => $rows,
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ], JSON_THROW_ON_ERROR)];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAction(Action $action): array
    {
        if ($action->getUrl() !== null) {
            return [
                'text' => $action->getLabel(),
                'url' => $action->getUrl(),
            ];
        }

        return [
            'text' => $action->getLabel(),
            'callback_data' => $this->encodeAction($action),
        ];
    }

    private function encodeAction(Action $action): string
    {
        return $this->callbackEncoder()->encode($action);
    }

    private function deliverAck(Context $context, AckEffect $effect): string
    {
        $callbackQueryId = $context->getMessageRef()?->getReplyToken();

        if ($context->isAction() && $callbackQueryId !== null && $callbackQueryId !== '') {
            try {
                $this->api->answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => $effect->getText(),
                    'show_alert' => $effect->isError(),
                ]);

                return 'telegram_ack_delivered';
            } catch (Throwable $exception) {
                $this->logger->notice('Telegram callback acknowledgement failed; continuing without blocking the flow.', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                    'conversation_id' => $context->getConversationId(),
                    'callback_query_id' => $callbackQueryId,
                ]);

                return 'telegram_ack_unavailable';
            }
        }

        if ($effect->getText() !== null && $effect->getText() !== '') {
            $this->sendView($context, View::text($effect->getText()), false);
        }

        return 'telegram_ack_delivered';
    }

    /**
     * @param array<string, mixed> $replyMarkup
     */
    private function sendMedia(Context $context, MediaAttachment $media, string $text, array $replyMarkup): mixed
    {
        $params = array_merge([
            'chat_id' => $context->getConversationId(),
            'caption' => $text,
        ], $replyMarkup);

        return match ($this->normalizeMediaType($media->getType())) {
            'photo' => $this->api->sendPhoto(array_merge($params, ['photo' => TelegramPublisher::normalizeMediaSource($media->getSource())])),
            'document' => $this->api->sendDocument(array_merge($params, ['document' => TelegramPublisher::normalizeMediaSource($media->getSource())])),
            'video' => $this->api->sendVideo(array_merge($params, ['video' => TelegramPublisher::normalizeMediaSource($media->getSource())])),
            'audio' => $this->api->sendAudio(array_merge($params, ['audio' => TelegramPublisher::normalizeMediaSource($media->getSource())])),
            'animation' => $this->api->sendAnimation(array_merge($params, ['animation' => TelegramPublisher::normalizeMediaSource($media->getSource())])),
            default => throw new ValidationException(sprintf('Unsupported media type "%s".', $media->getType())),
        };
    }

    private function isEditableMediaMessageType(string $messageType): bool
    {
        return in_array($messageType, ['photo', 'document', 'video', 'audio', 'animation'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInputMedia(MediaAttachment $media, string $caption, ?TelegramMessageOptions $options = null): array
    {
        $type = $this->normalizeMediaType($media->getType());
        if (!in_array($type, ['photo', 'document', 'video', 'audio', 'animation'], true)) {
            throw new ValidationException(sprintf('Unsupported media type "%s".', $media->getType()));
        }

        $payload = [
            'type' => $type,
            'media' => $media->getSource(),
        ];

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        $optionsParams = $options?->toTelegramParams() ?? [];
        if (isset($optionsParams['parse_mode']) && is_string($optionsParams['parse_mode'])) {
            $payload['parse_mode'] = $optionsParams['parse_mode'];
        }

        return $payload;
    }

    private function normalizeMediaType(string $type): string
    {
        return match ($type) {
            'image' => 'photo',
            default => $type,
        };
    }

    private function logRenderFallback(string $endpoint, Context $context, Throwable $exception): void
    {
        $this->logger->notice('Telegram render endpoint failed; falling back to send/delete strategy.', [
            'endpoint' => $endpoint,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'conversation_id' => $context->getConversationId(),
            'message_id' => $context->getMessageRef()?->get('message_id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeResponse(mixed $response): array
    {
        if (is_array($response)) {
            if (array_is_list($response)) {
                return ['messages' => $response];
            }

            /* @var array<string, mixed> $response */
            return $response;
        }

        if (is_object($response) && method_exists($response, 'toArray')) {
            $array = $response->toArray();
            if (!is_array($array)) {
                return ['value' => $array];
            }

            if (array_is_list($array)) {
                return ['messages' => $array];
            }

            /* @var array<string, mixed> $array */
            return $array;
        }

        if (is_bool($response)) {
            return ['ok' => $response];
        }

        return ['value' => $response];
    }
}
