<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Media;

use InvalidArgumentException;

final class TelegramMedia
{
    public function __construct(
        private readonly string $type,
        private readonly TelegramMediaSource $source,
        private readonly ?string $caption = null,
        private readonly ?string $parseMode = null,
        private readonly ?int $width = null,
        private readonly ?int $height = null,
        private readonly ?int $duration = null,
        private readonly ?bool $supportsStreaming = null,
    ) {
        if (!\in_array($type, ['photo', 'document', 'video', 'audio', 'animation'], true)) {
            throw new InvalidArgumentException(\sprintf('Unsupported Telegram media type "%s".', $type));
        }
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSource(): TelegramMediaSource
    {
        return $this->source;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function getParseMode(): ?string
    {
        return $this->parseMode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toInputMedia(): array
    {
        $payload = [
            'type' => $this->type,
            'media' => $this->source->toMediaGroupApiValue(),
        ];

        if ($this->caption !== null && $this->caption !== '') {
            $payload['caption'] = $this->caption;
        }

        if ($this->parseMode !== null && $this->parseMode !== '') {
            $payload['parse_mode'] = $this->parseMode;
        }

        if ($this->width !== null) {
            $payload['width'] = $this->width;
        }

        if ($this->height !== null) {
            $payload['height'] = $this->height;
        }

        if ($this->duration !== null) {
            $payload['duration'] = $this->duration;
        }

        if ($this->supportsStreaming !== null) {
            $payload['supports_streaming'] = $this->supportsStreaming;
        }

        return $payload;
    }
}
