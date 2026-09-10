<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

/**
 * Result of decoding inbound callback data.
 */
final class DecodedCallback
{
    public const ACCEPTED = 'accepted';
    public const EXPIRED = 'expired';
    public const REJECTED = 'rejected';

    /**
     * @param self::ACCEPTED|self::EXPIRED|self::REJECTED $status
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $actionId,
        public readonly mixed $payload,
        public readonly ?string $reason,
    ) {}

    public static function accepted(string $actionId, mixed $payload): self
    {
        return new self(self::ACCEPTED, $actionId, $payload, null);
    }

    /**
     * A stored payload token that no longer resolves, for example a button older than the store TTL.
     */
    public static function expired(): self
    {
        return new self(self::EXPIRED, null, null, 'stored payload expired');
    }

    /**
     * Callback data that was not produced by this bot: unsigned or tampered payloads.
     */
    public static function rejected(string $reason): self
    {
        return new self(self::REJECTED, null, null, $reason);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }
}
