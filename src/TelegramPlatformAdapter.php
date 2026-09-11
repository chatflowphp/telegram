<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\AfterHandleInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Contracts\PlatformAdapterInterface;
use ChatFlow\Contracts\RuntimeDependencyBinderInterface;
use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Event\InboundAttachment;
use ChatFlow\Event\InboundEvent;
use ChatFlow\Event\MessageRef;
use ChatFlow\Event\UserRef;
use ChatFlow\Exception\UnsupportedInputException;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Outbound\AckEffect;
use ChatFlow\Outbound\DeliveryResult;
use ChatFlow\Outbound\OutboundEffectInterface;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\Platform\PlatformCapabilities;
use ChatFlow\Telegram\Callback\TelegramCallbackPayloadEncoder;
use ChatFlow\Telegram\Exception\TelegramRateLimitException;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
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

/**
 * Converts Telegram updates into core inbound events and delivers core effects through the
 * Bot API.
 */
final class TelegramPlatformAdapter implements PlatformAdapterInterface, RuntimeDependencyBinderInterface, AfterHandleInterface
{
    private const ACKED_FLAG = 'telegram.callback_acked';

    private const MEDIA_TYPES = ['document', 'video', 'audio', 'voice', 'animation', 'sticker', 'video_note'];
    private const EDITABLE_MEDIA_TYPES = ['photo', 'document', 'video', 'audio', 'animation'];

    private readonly LoggerInterface $logger;

    private readonly TelegramRateLimiter $rateLimiter;

    public function __construct(
        private readonly Api $api,
        private readonly FileDownloader $fileDownloader,
        private readonly TelegramCallbackPayloadEncoder $callbackEncoder,
        private readonly ?TelegramScreenManager $screenManager = null,
        ?LoggerInterface $logger = null,
        private readonly ConversationScope $conversationScope = ConversationScope::Chat,
        ?TelegramRateLimiter $rateLimiter = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->rateLimiter = $rateLimiter ?? new TelegramRateLimiter();
    }

    /**
     * The conversation a chat and user belong to under the configured scope.
     */
    public function conversationIdFor(string|int $chatId, string|int|null $userId = null): string
    {
        return $this->conversationScope->conversationId($chatId, $userId);
    }

    public function bindRuntimeDependencies(ContainerInterface $container, Context $context): void
    {
        $container->scoped(TelegramContext::class, new TelegramContext($context));

        if ($this->screenManager !== null) {
            $container->scoped(TelegramScreenManager::class, $this->screenManager->forContext($context));
        }
    }

    /**
     * @throws UnsupportedInputException When the update carries no chat (inline queries, polls,
     *                                   shipping queries) or its callback data was not produced by this bot.
     */
    public function createInboundEvent(mixed $input): InboundEventInterface
    {
        $update = $input instanceof Update ? $input : new Update(\is_array($input) ? $input : []);
        $raw = self::stringKeys($update->toArray());
        $updateType = self::detectUpdateType($raw);

        $message = self::arrayOrNull($raw['message'] ?? $raw['edited_message'] ?? null);
        $callbackQuery = self::arrayOrNull($raw['callback_query'] ?? null);
        $membership = self::arrayOrNull($raw['my_chat_member'] ?? $raw['chat_member'] ?? null);
        $sourceMessage = $message ?? self::arrayOrNull($callbackQuery['message'] ?? null) ?? $membership ?? [];

        $chat = self::arrayOrNull($sourceMessage['chat'] ?? null) ?? [];
        $chatId = $chat['id'] ?? null;

        if (!\is_int($chatId) && !\is_string($chatId)) {
            throw new UnsupportedInputException(\sprintf('Telegram update of type "%s" carries no chat and cannot start a conversation.', $updateType));
        }

        $newChatMember = self::arrayOrNull($sourceMessage['new_chat_member'] ?? null);
        $from = self::arrayOrNull($sourceMessage['from'] ?? null)
            ?? self::arrayOrNull($newChatMember['user'] ?? null)
            ?? self::arrayOrNull($callbackQuery['from'] ?? null)
            ?? [];
        $userId = $from['id'] ?? null;

        $actionId = null;
        $actionPayload = null;
        $callbackStatus = null;

        if ($callbackQuery !== null && isset($callbackQuery['data']) && \is_string($callbackQuery['data'])) {
            $decoded = $this->callbackEncoder->decode($callbackQuery['data']);

            if ($decoded->isRejected()) {
                throw new UnsupportedInputException(\sprintf('Telegram callback data rejected: %s.', (string) $decoded->reason));
            }

            $actionId = $decoded->actionId;
            $actionPayload = $decoded->payload;
            $callbackStatus = $decoded->status;
        }

        $text = $sourceMessage['text'] ?? $sourceMessage['caption'] ?? '';
        $messageId = $sourceMessage['message_id'] ?? null;
        $callbackQueryId = $callbackQuery['id'] ?? null;

        return new InboundEvent(
            conversation: new ConversationRef(
                $this->conversationIdFor($chatId, \is_int($userId) || \is_string($userId) ? $userId : null),
                'telegram',
                ['chat' => self::filterSerializable($chat)],
            ),
            user: \is_int($userId) || \is_string($userId) ? new UserRef($userId, 'telegram', ['user' => self::filterSerializable($from)]) : null,
            text: \is_string($text) ? $text : '',
            actionId: $actionId,
            actionPayload: $actionPayload,
            attachments: $this->extractAttachments($sourceMessage),
            messageRef: new MessageRef(
                \is_scalar($messageId) ? (string) $messageId : null,
                null,
                \is_string($callbackQueryId) ? $callbackQueryId : null,
                self::filterSerializable([
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'callback_query_id' => $callbackQueryId,
                    'callback_status' => $callbackStatus,
                    'media_group_id' => $sourceMessage['media_group_id'] ?? null,
                    'message_type' => self::detectMessageType($sourceMessage),
                    'update_type' => $updateType,
                    'is_action' => $actionId !== null,
                ]),
            ),
            metadata: [
                'update_id' => $raw['update_id'] ?? null,
                'telegram' => self::filterSerializable($raw),
                'user' => self::filterSerializable($from),
                'chat' => self::filterSerializable($chat),
            ],
        );
    }

    public function deliver(Context $context, OutboundEffectInterface $effect): DeliveryResult
    {
        try {
            if ($effect instanceof ReplyEffect) {
                return DeliveryResult::success('telegram_reply_delivered', [
                    'telegram' => TelegramPublisher::normalizeResponse(
                        $this->rateLimiter->run(fn(): mixed => $this->sendView($context, $effect->getView(), false)),
                    ),
                ]);
            }

            if ($effect instanceof RenderEffect) {
                return DeliveryResult::success('telegram_render_delivered', [
                    'telegram' => TelegramPublisher::normalizeResponse(
                        $this->rateLimiter->run(fn(): mixed => $this->sendView($context, $effect->getView(), true)),
                    ),
                ]);
            }

            if ($effect instanceof AckEffect) {
                return DeliveryResult::success($this->rateLimiter->run(fn(): string => $this->deliverAck($context, $effect)));
            }
        } catch (TelegramRateLimitException $exception) {
            // The conversation is already stored; only the delivery is refused, and the caller
            // learns how long Telegram wants to wait.
            $this->logger->warning('Telegram rate limit reached', [
                'conversation_id' => $context->getConversationId(),
                'effect' => $effect->getType(),
                'retry_after' => $exception->retryAfter,
            ]);

            return DeliveryResult::error('telegram_rate_limited', [
                'retry_after' => $exception->retryAfter,
                'effect' => $effect->getType(),
            ]);
        } catch (Throwable $exception) {
            return DeliveryResult::error('telegram_delivery_failed', [
                'reason' => $exception->getMessage(),
                'effect' => $effect->getType(),
            ]);
        }

        return DeliveryResult::error('Unsupported Telegram outbound effect.');
    }

    /**
     * Every callback query must be answered or the client keeps its spinner for up to a minute.
     * Answers the query silently when no handler acknowledged it, including after failures.
     */
    public function afterHandle(Context $context, Result $result): void
    {
        if (!$context->isAction() || $context->get(self::ACKED_FLAG) === true) {
            return;
        }

        $callbackQueryId = $context->getMessageRef()?->getReplyToken();

        if ($callbackQueryId === null || $callbackQueryId === '') {
            return;
        }

        $context->set(self::ACKED_FLAG, true);

        try {
            $this->api->answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
        } catch (Throwable $exception) {
            $this->logger->notice('Telegram callback auto-acknowledgement failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'conversation_id' => $context->getConversationId(),
            ]);
        }
    }

    public function downloadAttachment(Context $context, string $destinationDir): ?string
    {
        $attachment = $context->getFirstAttachment();
        $fileId = $attachment?->getId() ?? $attachment?->get('file_id');

        if (!\is_string($fileId) || $fileId === '') {
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

    // -- inbound -------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $message
     *
     * @return list<InboundAttachment>
     */
    private function extractAttachments(array $message): array
    {
        $aggregated = $message[TelegramMediaGroupCollector::AGGREGATE_KEY] ?? null;

        if (\is_array($aggregated) && $aggregated !== []) {
            $attachments = [];

            foreach ($aggregated as $part) {
                if (\is_array($part)) {
                    $attachments = [...$attachments, ...$this->extractAttachments(self::stringKeys($part))];
                }
            }

            return $attachments;
        }

        $attachments = [];
        $commonMeta = self::filterSerializable([
            'caption' => $message['caption'] ?? null,
            'media_group_id' => $message['media_group_id'] ?? null,
            'message_id' => $message['message_id'] ?? null,
        ]);

        $photo = $message['photo'] ?? null;

        if (\is_array($photo) && $photo !== []) {
            $bestPhoto = end($photo);

            if (\is_array($bestPhoto) && isset($bestPhoto['file_id']) && \is_string($bestPhoto['file_id'])) {
                $attachments[] = new InboundAttachment(
                    type: 'photo',
                    id: $bestPhoto['file_id'],
                    size: \is_int($bestPhoto['file_size'] ?? null) ? $bestPhoto['file_size'] : null,
                    meta: array_merge($commonMeta, self::filterSerializable([
                        'file_id' => $bestPhoto['file_id'],
                        'file_unique_id' => $bestPhoto['file_unique_id'] ?? null,
                        'width' => $bestPhoto['width'] ?? null,
                        'height' => $bestPhoto['height'] ?? null,
                        'sizes' => $photo,
                    ])),
                );
            }
        }

        foreach (self::MEDIA_TYPES as $type) {
            $attachment = $message[$type] ?? null;

            if (!\is_array($attachment) || !isset($attachment['file_id']) || !\is_string($attachment['file_id'])) {
                continue;
            }

            $attachments[] = new InboundAttachment(
                type: $type,
                id: $attachment['file_id'],
                name: \is_string($attachment['file_name'] ?? null) ? $attachment['file_name'] : null,
                mimeType: \is_string($attachment['mime_type'] ?? null) ? $attachment['mime_type'] : null,
                size: \is_int($attachment['file_size'] ?? null) ? $attachment['file_size'] : null,
                meta: array_merge($commonMeta, self::filterSerializable([
                    'file_id' => $attachment['file_id'],
                    'file_unique_id' => $attachment['file_unique_id'] ?? null,
                    'width' => $attachment['width'] ?? null,
                    'height' => $attachment['height'] ?? null,
                    'duration' => $attachment['duration'] ?? null,
                    'supports_streaming' => $attachment['supports_streaming'] ?? null,
                    'thumb' => $attachment['thumb'] ?? ($attachment['thumbnail'] ?? null),
                ])),
            );
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function detectMessageType(array $message): string
    {
        foreach (['photo', ...self::MEDIA_TYPES] as $type) {
            if (isset($message[$type])) {
                return $type;
            }
        }

        return 'text';
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function detectUpdateType(array $raw): string
    {
        foreach (array_keys($raw) as $key) {
            if ($key !== 'update_id') {
                return $key;
            }
        }

        return 'unknown';
    }

    // -- outbound ------------------------------------------------------------------------------

    private function sendView(Context $context, View $view, bool $preferRender): mixed
    {
        $messageRefData = $context->getMessageRef()?->getPlatformData() ?? [];
        $replyMarkup = $this->buildReplyMarkup($view);
        $options = $this->extractTelegramOptions($view);
        $optionsParams = $options->toTelegramParams();
        $hasMedia = $view->getMedia() !== [];
        $usesChoices = $view->getChoices() !== [];
        $currentMessageType = $messageRefData['message_type'] ?? 'text';
        $canEdit = $preferRender && $context->isAction() && !$usesChoices && isset($messageRefData['chat_id'], $messageRefData['message_id']);
        $target = ['chat_id' => $messageRefData['chat_id'] ?? null, 'message_id' => $messageRefData['message_id'] ?? null];

        if ($canEdit && !$hasMedia && $currentMessageType === 'text') {
            try {
                return $this->api->editMessageText(array_merge($target, ['text' => $view->getText()], $replyMarkup, $optionsParams));
            } catch (Throwable $exception) {
                if (self::isNotModified($exception)) {
                    return ['ok' => true, 'not_modified' => true];
                }

                $this->logRenderFallback('editMessageText', $context, $exception);
            }
        }

        if ($canEdit && $hasMedia && \is_string($currentMessageType) && \in_array($currentMessageType, self::EDITABLE_MEDIA_TYPES, true)) {
            try {
                return $this->api->editMessageMedia(array_merge($target, [
                    'media' => json_encode($this->buildInputMedia($view->getMedia()[0], $view->getText(), $options), JSON_THROW_ON_ERROR),
                ], $replyMarkup, $optionsParams));
            } catch (Throwable $exception) {
                if (self::isNotModified($exception)) {
                    return ['ok' => true, 'not_modified' => true];
                }

                $this->logRenderFallback('editMessageMedia', $context, $exception);
            }
        }

        if ($preferRender && $context->isAction() && isset($messageRefData['chat_id'], $messageRefData['message_id'])) {
            try {
                $this->api->deleteMessage($target);
            } catch (Throwable $exception) {
                $this->logRenderFallback('deleteMessage', $context, $exception);
            }
        }

        if ($hasMedia) {
            return $this->sendMedia($context, $view->getMedia()[0], $view->getText(), array_merge($replyMarkup, $optionsParams));
        }

        return $this->api->sendMessage(array_merge([
            'chat_id' => self::chatIdFor($context),
            'text' => $view->getText(),
        ], $replyMarkup, $optionsParams));
    }

    /**
     * The Telegram chat a conversation belongs to. The conversation id is the chat id by default,
     * but a conversation scoped to one member of a group has its own id and keeps the chat in the
     * conversation meta; messages always go to the chat.
     */
    public static function chatIdFor(Context $context): string
    {
        $chat = $context->getConversation()->getMeta()['chat'] ?? null;
        $chatId = \is_array($chat) ? $chat['id'] ?? null : null;

        return \is_int($chatId) || \is_string($chatId) ? (string) $chatId : $context->getConversationId();
    }

    private function extractTelegramOptions(View $view): TelegramMessageOptions
    {
        $telegramMeta = $view->getMeta()['telegram'] ?? [];

        return \is_array($telegramMeta) ? TelegramMessageOptions::fromMeta(self::stringKeys($telegramMeta)) : new TelegramMessageOptions();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReplyMarkup(View $view): array
    {
        if ($view->getActions() !== []) {
            $rows = array_map(
                fn(array $row): array => array_map(fn(Action $action): array => $this->mapAction($action), $row),
                $view->getActions(),
            );

            return ['reply_markup' => json_encode(['inline_keyboard' => $rows], JSON_THROW_ON_ERROR)];
        }

        if ($view->getChoices() !== []) {
            $rows = array_map(
                static fn(array $row): array => array_map(static fn(Choice $choice): array => [
                    'text' => $choice->getValue() !== '' ? $choice->getValue() : $choice->getLabel(),
                ], $row),
                $view->getChoices(),
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
            return ['text' => $action->getLabel(), 'url' => $action->getUrl()];
        }

        return ['text' => $action->getLabel(), 'callback_data' => $this->callbackEncoder->encode($action)];
    }

    private function deliverAck(Context $context, AckEffect $effect): string
    {
        $callbackQueryId = $context->getMessageRef()?->getReplyToken();

        if ($context->isAction() && $callbackQueryId !== null && $callbackQueryId !== '') {
            $context->set(self::ACKED_FLAG, true);

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
     * @param array<string, mixed> $extra
     */
    private function sendMedia(Context $context, MediaAttachment $media, string $text, array $extra): mixed
    {
        $params = array_merge(['chat_id' => self::chatIdFor($context), 'caption' => $text], $extra);
        $source = TelegramPublisher::normalizeMediaSource($media->getSource());

        return match (self::normalizeMediaType($media->getType())) {
            'photo' => $this->api->sendPhoto(array_merge($params, ['photo' => $source])),
            'document' => $this->api->sendDocument(array_merge($params, ['document' => $source])),
            'video' => $this->api->sendVideo(array_merge($params, ['video' => $source])),
            'audio' => $this->api->sendAudio(array_merge($params, ['audio' => $source])),
            'animation' => $this->api->sendAnimation(array_merge($params, ['animation' => $source])),
            default => throw new ValidationException(\sprintf('Unsupported media type "%s".', $media->getType())),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInputMedia(MediaAttachment $media, string $caption, TelegramMessageOptions $options): array
    {
        $type = self::normalizeMediaType($media->getType());

        if (!\in_array($type, self::EDITABLE_MEDIA_TYPES, true)) {
            throw new ValidationException(\sprintf('Unsupported media type "%s".', $media->getType()));
        }

        $payload = ['type' => $type, 'media' => $media->getSource()];

        if ($caption !== '') {
            $payload['caption'] = $caption;
        }

        $parseMode = $options->toTelegramParams()['parse_mode'] ?? null;

        if (\is_string($parseMode)) {
            $payload['parse_mode'] = $parseMode;
        }

        return $payload;
    }

    private static function normalizeMediaType(string $type): string
    {
        return $type === 'image' ? 'photo' : $type;
    }

    private static function isNotModified(Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'message is not modified');
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

    // -- helpers -------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private static function arrayOrNull(mixed $value): ?array
    {
        return \is_array($value) ? self::stringKeys($value) : null;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * Keeps scalars and nested arrays of scalars, dropping nulls and objects.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function filterSerializable(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (\is_array($value)) {
                $filtered[(string) $key] = self::filterSerializableNested($value);

                continue;
            }

            if (\is_scalar($value)) {
                $filtered[(string) $key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function filterSerializableNested(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (\is_array($value)) {
                $filtered[$key] = self::filterSerializableNested($value);

                continue;
            }

            if (\is_scalar($value)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
