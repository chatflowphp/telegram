<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

interface TelegramCallbackStoreInterface
{
    /**
     * @param array{id: string, payload: mixed} $payload
     */
    public function put(array $payload): string;

    /**
     * @return array{id: string, payload: mixed}|null
     */
    public function get(string $token): ?array;

    public function delete(string $token): void;
}
