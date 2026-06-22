<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

use ChatFlow\View\Action;
use InvalidArgumentException;
use RuntimeException;

final class TelegramCallbackPayloadEncoder
{
    private const STORED_PREFIX = 'cf:';

    public function __construct(
        private readonly TelegramCallbackStoreInterface $store,
        private readonly int $maxBytes = 64,
    ) {
        if ($this->maxBytes < 8) {
            throw new InvalidArgumentException('Telegram callback max bytes must be at least 8.');
        }
    }

    public function encode(Action $action): string
    {
        if ($action->getPayload() === null && strlen($action->getId()) <= $this->maxBytes) {
            return $action->getId();
        }

        $payload = [
            'id' => $action->getId(),
            'payload' => $action->getPayload(),
        ];
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        if (strlen($encoded) <= $this->maxBytes) {
            return $encoded;
        }

        $token = $this->store->put($payload);
        $callbackData = self::STORED_PREFIX . $token;

        if (strlen($callbackData) > $this->maxBytes) {
            throw new RuntimeException('Stored Telegram callback token exceeds Telegram callback_data limit.');
        }

        return $callbackData;
    }

    /**
     * @return array{0: ?string, 1: mixed}
     */
    public function decode(string $callbackData): array
    {
        if (str_starts_with($callbackData, self::STORED_PREFIX)) {
            $token = substr($callbackData, strlen(self::STORED_PREFIX));
            $stored = $this->store->get($token);

            return $stored === null ? [null, null] : [$stored['id'], $stored['payload']];
        }

        $decoded = json_decode($callbackData, true);
        if (is_array($decoded) && isset($decoded['id']) && is_string($decoded['id'])) {
            return [$decoded['id'], $decoded['payload'] ?? null];
        }

        return [$callbackData, null];
    }
}
