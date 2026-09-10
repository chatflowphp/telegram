<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

use ChatFlow\View\Action;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Fits action ids and payloads into Telegram's 64-byte callback_data.
 *
 * Telegram does not check that callback data sent by a client matches a button the bot rendered,
 * so payloads are authenticated:
 *
 * - `id` for actions without payload;
 * - `cs:<signature>:<json>` for small payloads, signed with HMAC-SHA256 truncated to 12 hex chars;
 * - `cf:<token>` for larger payloads stored behind a random token.
 */
final class TelegramCallbackPayloadEncoder
{
    public const STORED_PREFIX = 'cf:';
    public const SIGNED_PREFIX = 'cs:';

    private const SIGNATURE_LENGTH = 12;

    public function __construct(
        private readonly TelegramCallbackStoreInterface $store,
        private readonly string $secret,
        private readonly int $maxBytes = 64,
    ) {
        if ($this->secret === '') {
            throw new InvalidArgumentException('Telegram callback secret must not be empty.');
        }

        if ($this->maxBytes < 24) {
            throw new InvalidArgumentException('Telegram callback max bytes must be at least 24.');
        }
    }

    public function encode(Action $action): string
    {
        $payload = $action->getPayload();

        if ($payload === null && \strlen($action->getId()) <= $this->maxBytes) {
            return $action->getId();
        }

        try {
            $json = json_encode(['id' => $action->getId(), 'payload' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Telegram callback payload cannot be encoded: ' . $e->getMessage(), 0, $e);
        }

        $signed = self::SIGNED_PREFIX . $this->sign($json) . ':' . $json;

        if (\strlen($signed) <= $this->maxBytes) {
            return $signed;
        }

        $callbackData = self::STORED_PREFIX . $this->store->put(['id' => $action->getId(), 'payload' => $payload]);

        if (\strlen($callbackData) > $this->maxBytes) {
            throw new RuntimeException('Stored Telegram callback token exceeds the callback_data limit.');
        }

        return $callbackData;
    }

    public function decode(string $callbackData): DecodedCallback
    {
        if (str_starts_with($callbackData, self::STORED_PREFIX)) {
            $stored = $this->store->get(substr($callbackData, \strlen(self::STORED_PREFIX)));

            return $stored === null
                ? DecodedCallback::expired()
                : DecodedCallback::accepted($stored['id'], $stored['payload']);
        }

        if (str_starts_with($callbackData, self::SIGNED_PREFIX)) {
            return $this->decodeSigned(substr($callbackData, \strlen(self::SIGNED_PREFIX)));
        }

        if (str_starts_with($callbackData, '{')) {
            return DecodedCallback::rejected('unsigned payload');
        }

        return DecodedCallback::accepted($callbackData, null);
    }

    private function decodeSigned(string $data): DecodedCallback
    {
        $separator = strpos($data, ':');

        if ($separator !== self::SIGNATURE_LENGTH) {
            return DecodedCallback::rejected('malformed signature');
        }

        $signature = substr($data, 0, $separator);
        $json = substr($data, $separator + 1);

        if (!hash_equals($this->sign($json), $signature)) {
            return DecodedCallback::rejected('invalid signature');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return DecodedCallback::rejected('malformed payload');
        }

        if (!\is_array($decoded) || !isset($decoded['id']) || !\is_string($decoded['id'])) {
            return DecodedCallback::rejected('malformed payload');
        }

        return DecodedCallback::accepted($decoded['id'], $decoded['payload'] ?? null);
    }

    private function sign(string $json): string
    {
        return substr(hash_hmac('sha256', $json, $this->secret), 0, self::SIGNATURE_LENGTH);
    }
}
