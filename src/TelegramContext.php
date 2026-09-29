<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Core\Context;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Outbound\ReplyEffect;
use ChatFlow\View\View;
use Telegram\Bot\Api;

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

    public function publisher(): ?TelegramPublisher
    {
        $container = $this->context->getContainer();
        if ($container->has(TelegramPublisher::class)) {
            $publisher = $container->get(TelegramPublisher::class);
            if ($publisher instanceof TelegramPublisher) {
                return $publisher;
            }
        }

        return null;
    }

    public function api(): ?Api
    {
        $container = $this->context->getContainer();
        if ($container->has(Api::class)) {
            $api = $container->get(Api::class);
            if ($api instanceof Api) {
                return $api;
            }
        }

        return null;
    }

    /**
     * Edits the reply markup of a message, defaulting to the current message in context.
     *
     * @param array<string, mixed>|string|null $replyMarkup Array for inline_keyboard, encoded JSON string, or null to remove buttons
     */
    public function editReplyMarkup(array|string|null $replyMarkup = null, ?int $messageId = null): ?TelegramDeliveryResult
    {
        $targetMessageId = $messageId ?? $this->getMessageId();
        if ($targetMessageId === null) {
            return null;
        }

        return $this->publisher()?->editReplyMarkup($this->getChatId(), $targetMessageId, $replyMarkup);
    }

    /**
     * Clears all inline keyboard buttons from the message.
     */
    public function removeButtons(?int $messageId = null): ?TelegramDeliveryResult
    {
        return $this->editReplyMarkup(null, $messageId);
    }
}
