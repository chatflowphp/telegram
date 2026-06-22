<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

final class TelegramDeliveryResult
{
    /**
     * @param array<string, mixed> $rawResponse
     */
    public function __construct(
        private readonly string|int $chatId,
        private readonly ?int $messageId,
        private readonly string $endpoint,
        private readonly array $rawResponse,
    ) {
    }

    public function getChatId(): string|int
    {
        return $this->chatId;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
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
