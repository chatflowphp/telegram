<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

final class InMemoryTelegramCallbackStore implements TelegramCallbackStoreInterface
{
    /** @var array<string, array{id: string, payload: mixed}> */
    private array $items = [];

    public function put(array $payload): string
    {
        do {
            $token = bin2hex(random_bytes(8));
        } while (isset($this->items[$token]));

        $this->items[$token] = $payload;

        return $token;
    }

    public function get(string $token): ?array
    {
        return $this->items[$token] ?? null;
    }

    public function delete(string $token): void
    {
        unset($this->items[$token]);
    }
}
