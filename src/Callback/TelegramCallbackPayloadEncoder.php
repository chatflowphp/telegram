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
 * - `cs:<signature>:["id",payload]` for small payloads, HMAC-SHA256 signed;
 * - `cf:<token>` for larger payloads, where the token is derived from the payload and the secret,
 *   so rendering the same button again reuses the stored record.
 */
final class TelegramCallbackPayloadEncoder
{
    public const STORED_PREFIX = 'cf:';
    public const SIGNED_PREFIX = 'cs:';

    private const SIGNATURE_LENGTH = 10;
    private const TOKEN_LENGTH = 16;

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
            $json = json_encode([$action->getId(), $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new RuntimeException('Telegram callback payload cannot be encoded: ' . $e->getMessage(), 0, $e);
        }

        $signed = self::SIGNED_PREFIX . $this->digest('sign', $json, self::SIGNATURE_LENGTH) . ':' . $json;

        if (\strlen($signed) <= $this->maxBytes) {
            return $signed;
        }

        $token = $this->digest('token', $json, self::TOKEN_LENGTH);
        $this->store->put($token, ['id' => $action->getId(), 'payload' => $payload]);

        return self::STORED_PREFIX . $token;
    }

    public function decode(string $callbackData): DecodedCallback
    {
        if (str_starts_with($callbackData, self::STORED_PREFIX)) {
            $token = substr($callbackData, \strlen(self::STORED_PREFIX));

            if (preg_match('/^[0-9a-f]{' . self::TOKEN_LENGTH . '}$/', $token) !== 1) {
                return DecodedCallback::rejected('malformed token');
            }

            $stored = $this->store->get($token);

            return $stored === null
                ? DecodedCallback::expired()
                : DecodedCallback::accepted($stored['id'], $stored['payload']);
        }

        if (str_starts_with($callbackData, self::SIGNED_PREFIX)) {
            return $this->decodeSigned(substr($callbackData, \strlen(self::SIGNED_PREFIX)));
        }

        if (str_starts_with($callbackData, '{') || str_starts_with($callbackData, '[')) {
            return DecodedCallback::rejected('unsigned payload');
        }

        return DecodedCallback::accepted($callbackData, null);
    }

    private function decodeSigned(string $data): DecodedCallback
    {
        if (\strlen($data) <= self::SIGNATURE_LENGTH + 1 || $data[self::SIGNATURE_LENGTH] !== ':') {
            return DecodedCallback::rejected('malformed signature');
        }

        $signature = substr($data, 0, self::SIGNATURE_LENGTH);
        $json = substr($data, self::SIGNATURE_LENGTH + 1);

        if (!hash_equals($this->digest('sign', $json, self::SIGNATURE_LENGTH), $signature)) {
            return DecodedCallback::rejected('invalid signature');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return DecodedCallback::rejected('malformed payload');
        }

        if (!\is_array($decoded) || !array_is_list($decoded) || \count($decoded) !== 2 || !\is_string($decoded[0])) {
            return DecodedCallback::rejected('malformed payload');
        }

        return DecodedCallback::accepted($decoded[0], $decoded[1]);
    }

    private function digest(string $purpose, string $json, int $length): string
    {
        return substr(hash_hmac('sha256', $purpose . ':' . $json, $this->secret), 0, $length);
    }
}
