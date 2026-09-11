<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Core\Context;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\View\View;

final class TelegramContext
{
    public function __construct(private readonly Context $context) {}

    public function core(): Context
    {
        return $this->context;
    }

    public function reply(View|string $view, ?TelegramMessageOptions $options = null): ReplyEffect
    {
        return $this->context->reply($options === null ? $view : TelegramView::options($view, $options));
    }

    public function render(View|string $view, ?TelegramMessageOptions $options = null): RenderEffect
    {
        $normalized = $view instanceof View ? $view : View::text($view);

        return $this->context->render($options === null ? $normalized : TelegramView::options($normalized, $options));
    }

    public function forceReply(
        View|string $view,
        ?int $replyToMessageId = null,
        ?TelegramMessageOptions $options = null,
    ): ReplyEffect {
        $options ??= new TelegramMessageOptions(parseMode: 'HTML');
        $messageId = $replyToMessageId ?? $this->getMessageId();

        return $this->context->reply(
            TelegramView::options($view, $options->withReplyToMessageId($messageId)->withForceReply()),
        );
    }

    public function getChatId(): string|int
    {
        $chatId = $this->context->getMessageRef()?->get('chat_id');

        return \is_int($chatId) || \is_string($chatId) ? $chatId : TelegramPlatformAdapter::chatIdFor($this->context);
    }

    public function getMessageId(): ?int
    {
        $messageId = $this->context->getMessageRef()?->get('message_id');

        return \is_int($messageId) ? $messageId : null;
    }

    public function getCallbackQueryId(): ?string
    {
        $callbackQueryId = $this->context->getMessageRef()?->get('callback_query_id')
            ?? $this->context->getMessageRef()?->getReplyToken();

        return \is_string($callbackQueryId) && $callbackQueryId !== '' ? $callbackQueryId : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawUpdate(): array
    {
        $raw = $this->context->getMetadata()['telegram'] ?? [];

        if (!\is_array($raw)) {
            return [];
        }

        $update = [];

        foreach ($raw as $key => $value) {
            $update[(string) $key] = $value;
        }

        return $update;
    }
}
