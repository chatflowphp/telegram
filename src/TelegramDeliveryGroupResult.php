<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

final class TelegramDeliveryGroupResult
{
    /**
     * @param list<int> $messageIds
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        private readonly string|int $chatId,
        private readonly array $messageIds,
        private readonly string $endpoint,
        private readonly array $rawResponse,
    ) {}

    public function getChatId(): string|int
    {
        return $this->chatId;
    }

    /**
     * @return list<int>
     */
    public function getMessageIds(): array
    {
        return $this->messageIds;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }
}
