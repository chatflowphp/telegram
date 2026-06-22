<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Support\SerializableValueValidator;

final class TelegramMessageOptions
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        private readonly ?string $parseMode = null,
        private readonly ?bool $disableWebPagePreview = null,
        private readonly ?int $replyToMessageId = null,
        private readonly bool $forceReply = false,
        private readonly ?bool $selective = null,
        private readonly ?bool $protectContent = null,
        private readonly array $extra = [],
    ) {
        SerializableValueValidator::assertSerializable($this->extra, 'telegram message options extra');
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function html(array $extra = []): self
    {
        return new self(parseMode: 'HTML', extra: $extra);
    }

    public function withParseMode(?string $parseMode): self
    {
        return new self($parseMode, $this->disableWebPagePreview, $this->replyToMessageId, $this->forceReply, $this->selective, $this->protectContent, $this->extra);
    }

    public function withDisableWebPagePreview(?bool $disableWebPagePreview): self
    {
        return new self($this->parseMode, $disableWebPagePreview, $this->replyToMessageId, $this->forceReply, $this->selective, $this->protectContent, $this->extra);
    }

    public function withReplyToMessageId(?int $replyToMessageId): self
    {
        return new self($this->parseMode, $this->disableWebPagePreview, $replyToMessageId, $this->forceReply, $this->selective, $this->protectContent, $this->extra);
    }

    public function withForceReply(bool $forceReply = true, ?bool $selective = null): self
    {
        return new self($this->parseMode, $this->disableWebPagePreview, $this->replyToMessageId, $forceReply, $selective, $this->protectContent, $this->extra);
    }

    public function withProtectContent(?bool $protectContent): self
    {
        return new self($this->parseMode, $this->disableWebPagePreview, $this->replyToMessageId, $this->forceReply, $this->selective, $protectContent, $this->extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function withExtra(array $extra): self
    {
        return new self($this->parseMode, $this->disableWebPagePreview, $this->replyToMessageId, $this->forceReply, $this->selective, $this->protectContent, $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        return array_filter([
            'parse_mode' => $this->parseMode,
            'disable_web_page_preview' => $this->disableWebPagePreview,
            'reply_to_message_id' => $this->replyToMessageId,
            'force_reply' => $this->forceReply,
            'selective' => $this->selective,
            'protect_content' => $this->protectContent,
            'extra' => SerializableValueValidator::normalizeMap($this->extra, 'telegram message options extra'),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function fromMeta(array $meta): self
    {
        $extra = $meta['extra'] ?? [];
        if (!is_array($extra)) {
            $extra = [];
        }

        return new self(
            parseMode: isset($meta['parse_mode']) && is_string($meta['parse_mode']) ? $meta['parse_mode'] : null,
            disableWebPagePreview: isset($meta['disable_web_page_preview']) && is_bool($meta['disable_web_page_preview']) ? $meta['disable_web_page_preview'] : null,
            replyToMessageId: isset($meta['reply_to_message_id']) && is_int($meta['reply_to_message_id']) ? $meta['reply_to_message_id'] : null,
            forceReply: isset($meta['force_reply']) && is_bool($meta['force_reply']) ? $meta['force_reply'] : false,
            selective: isset($meta['selective']) && is_bool($meta['selective']) ? $meta['selective'] : null,
            protectContent: isset($meta['protect_content']) && is_bool($meta['protect_content']) ? $meta['protect_content'] : null,
            extra: $extra,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toTelegramParams(): array
    {
        $params = SerializableValueValidator::normalizeMap($this->extra, 'telegram message options extra');

        if ($this->parseMode !== null && $this->parseMode !== '') {
            $params['parse_mode'] = $this->parseMode;
        }

        if ($this->disableWebPagePreview !== null) {
            $params['disable_web_page_preview'] = $this->disableWebPagePreview;
        }

        if ($this->replyToMessageId !== null) {
            $params['reply_to_message_id'] = $this->replyToMessageId;
        }

        if ($this->protectContent !== null) {
            $params['protect_content'] = $this->protectContent;
        }

        if ($this->forceReply) {
            $replyMarkup = ['force_reply' => true];
            if ($this->selective !== null) {
                $replyMarkup['selective'] = $this->selective;
            }

            $params['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
        }

        return $params;
    }
}
