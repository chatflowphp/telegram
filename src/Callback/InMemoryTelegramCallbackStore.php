<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

final class InMemoryTelegramCallbackStore implements TelegramCallbackStoreInterface
{
    /**
     * @var array<string, array{id: string, payload: mixed}>
     */
    private array $items = [];

    public function put(string $token, array $payload): void
    {
        $this->items[$token] = $payload;
    }

    public function get(string $token): ?array
    {
        return $this->items[$token] ?? null;
    }

    public function delete(string $token): void
    {
        unset($this->items[$token]);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
