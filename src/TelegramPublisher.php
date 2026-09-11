<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Telegram\Media\TelegramMedia;
use InvalidArgumentException;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;

final class TelegramPublisher
{
    private readonly TelegramRateLimiter $rateLimiter;

    public function __construct(
        private readonly Api $api,
        ?TelegramRateLimiter $rateLimiter = null,
    ) {
        $this->rateLimiter = $rateLimiter ?? new TelegramRateLimiter();
    }

    /**
     * Every Bot API call of the publisher goes through the rate limiter, so a throttled broadcast
     * raises TelegramRateLimitException with the wait Telegram asked for instead of failing blind.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function call(callable $call): mixed
    {
        return $this->rateLimiter->run($call);
    }

    public function sendMessage(
        string|int $chatId,
        string $text,
        ?TelegramMessageOptions $options = null,
    ): TelegramDeliveryResult {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options?->toTelegramParams() ?? []);

        $response = $this->call(fn(): mixed => $this->api->sendMessage($params));

        return $this->singleResult($chatId, 'sendMessage', $response);
    }

    public function sendMedia(
        string|int $chatId,
        TelegramMedia $media,
        ?TelegramMessageOptions $options = null,
    ): TelegramDeliveryResult {
        $params = array_merge([
            'chat_id' => $chatId,
        ], $options?->toTelegramParams() ?? []);

        if ($media->getCaption() !== null && $media->getCaption() !== '') {
            $params['caption'] = $media->getCaption();
        }

        if ($media->getParseMode() !== null && $media->getParseMode() !== '') {
            $params['parse_mode'] = $media->getParseMode();
        }

        $type = $media->getType();
        $endpoints = [
            'photo' => 'sendPhoto',
            'document' => 'sendDocument',
            'video' => 'sendVideo',
            'audio' => 'sendAudio',
            'animation' => 'sendAnimation',
        ];
        $fields = [
            'photo' => 'photo',
            'document' => 'document',
            'video' => 'video',
            'audio' => 'audio',
            'animation' => 'animation',
        ];
        if (!isset($endpoints[$type], $fields[$type])) {
            throw new InvalidArgumentException(\sprintf('Unsupported Telegram media type "%s".', $type));
        }

        $endpoint = $endpoints[$type];
        $field = $fields[$type];

        $params[$field] = $media->getSource()->toSingleApiValue();

        if ($endpoint === 'sendPhoto') {
            $response = $this->call(fn(): mixed => $this->api->sendPhoto($params));
        } elseif ($endpoint === 'sendDocument') {
            $response = $this->call(fn(): mixed => $this->api->sendDocument($params));
        } elseif ($endpoint === 'sendVideo') {
            $response = $this->call(fn(): mixed => $this->api->sendVideo($params));
        } elseif ($endpoint === 'sendAudio') {
            $response = $this->call(fn(): mixed => $this->api->sendAudio($params));
        } else {
            $response = $this->call(fn(): mixed => $this->api->sendAnimation($params));
        }

        return $this->singleResult($chatId, $endpoint, $response);
    }

    /**
     * @param list<TelegramMedia> $media
     */
    public function sendMediaGroup(
        string|int $chatId,
        array $media,
        ?TelegramMessageOptions $options = null,
    ): TelegramDeliveryGroupResult {
        if (\count($media) < 2 || \count($media) > 10) {
            throw new InvalidArgumentException('Telegram media group must contain 2-10 media items.');
        }

        $params = array_merge([
            'chat_id' => $chatId,
            'media' => json_encode(array_map(
                static fn(TelegramMedia $item): array => $item->toInputMedia(),
                $media,
            ), JSON_THROW_ON_ERROR),
        ], $options?->toTelegramParams() ?? []);

        $response = $this->call(fn(): mixed => $this->api->sendMediaGroup($params));
        $raw = self::normalizeResponse($response);

        return new TelegramDeliveryGroupResult(
            chatId: $chatId,
            messageIds: $this->extractMessageIds($raw),
            endpoint: 'sendMediaGroup',
            rawResponse: $raw,
        );
    }

    public function editText(
        string|int $chatId,
        int $messageId,
        string $text,
        ?TelegramMessageOptions $options = null,
    ): TelegramDeliveryResult {
        $response = $this->call(fn(): mixed => $this->api->editMessageText(array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $options?->toTelegramParams() ?? [])));

        return $this->singleResult($chatId, 'editMessageText', $response);
    }

    public function editCaption(
        string|int $chatId,
        int $messageId,
        string $caption,
        ?TelegramMessageOptions $options = null,
    ): TelegramDeliveryResult {
        $response = $this->call(fn(): mixed => $this->api->editMessageCaption(array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
        ], $options?->toTelegramParams() ?? [])));

        return $this->singleResult($chatId, 'editMessageCaption', $response);
    }

    public function deleteMessage(string|int $chatId, int $messageId): TelegramDeliveryResult
    {
        $response = $this->call(fn(): mixed => $this->api->deleteMessage([
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]));

        return new TelegramDeliveryResult($chatId, $messageId, 'deleteMessage', self::normalizeResponse($response));
    }

    public static function normalizeMediaSource(string $source): string|InputFile
    {
        if (is_file($source)) {
            return InputFile::create($source);
        }

        return $source;
    }

    private function singleResult(string|int $chatId, string $endpoint, mixed $response): TelegramDeliveryResult
    {
        $raw = self::normalizeResponse($response);
        $messageId = $raw['message_id'] ?? null;

        return new TelegramDeliveryResult(
            chatId: $chatId,
            messageId: \is_int($messageId) ? $messageId : null,
            endpoint: $endpoint,
            rawResponse: $raw,
        );
    }

    /**
     * Turns an SDK response (object, list, map, bool) into a string-keyed array.
     *
     * @return array<string, mixed>
     */
    public static function normalizeResponse(mixed $response): array
    {
        if (\is_object($response) && method_exists($response, 'toArray')) {
            $response = $response->toArray();
        }

        if (\is_bool($response)) {
            return ['ok' => $response];
        }

        if (!\is_array($response)) {
            return ['value' => $response];
        }

        if (array_is_list($response)) {
            return ['messages' => $response];
        }

        $normalized = [];

        foreach ($response as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<int>
     */
    private function extractMessageIds(array $raw): array
    {
        $items = $raw['messages'] ?? $raw['result'] ?? [];
        if (!\is_array($items)) {
            return [];
        }

        $ids = [];
        foreach ($items as $item) {
            if (\is_array($item) && isset($item['message_id']) && \is_int($item['message_id'])) {
                $ids[] = $item['message_id'];
            }
        }

        return $ids;
    }
}
