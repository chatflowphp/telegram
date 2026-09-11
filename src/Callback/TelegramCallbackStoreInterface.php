<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

/**
 * Keeps callback payloads that do not fit into Telegram's callback_data behind tokens chosen by
 * the encoder. Tokens are content-addressed: storing the same payload again must be a no-op.
 */
interface TelegramCallbackStoreInterface
{
    /**
     * @param array{id: string, payload: mixed} $payload
     */
    public function put(string $token, array $payload): void;

    /**
     * @return array{id: string, payload: mixed}|null
     */
    public function get(string $token): ?array;

    public function delete(string $token): void;
}
