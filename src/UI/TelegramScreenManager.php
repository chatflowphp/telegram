<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\UI;

use ChatFlow\Core\Context;
use ChatFlow\Outbound\RenderEffect;
use ChatFlow\Telegram\TelegramDeliveryGroupResult;
use ChatFlow\Telegram\TelegramDeliveryResult;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\View\View;
use Throwable;

final class TelegramScreenManager
{
    /** @var array<string, list<array{chat_id: string|int, message_id: int}>> */
    private array $groups = [];

    public function __construct(
        private readonly TelegramPublisher $publisher,
        private readonly ?Context $context = null,
        private ?string $activeGroup = null,
    ) {
    }

    public function forContext(Context $context): self
    {
        $clone = new self($this->publisher, $context, $this->activeGroup);
        $clone->groups = &$this->groups;

        return $clone;
    }

    public function beginGroup(string $name, bool $clearPrevious = true): void
    {
        if ($clearPrevious) {
            $this->clearGroup($name);
        }

        $this->activeGroup = $name;
    }

    public function clearGroup(string $name): void
    {
        $key = $this->groupKey($name);
        foreach ($this->groups[$key] ?? [] as $message) {
            try {
                $this->publisher->deleteMessage($message['chat_id'], $message['message_id']);
            } catch (Throwable) {
                // Screen cleanup must never break the user-facing flow.
            }
        }

        unset($this->groups[$key]);
    }

    public function track(TelegramDeliveryResult|TelegramDeliveryGroupResult $result, ?string $group = null): void
    {
        $group ??= $this->activeGroup;
        if ($group === null) {
            return;
        }

        $messageIds = $result instanceof TelegramDeliveryGroupResult
            ? $result->getMessageIds()
            : array_filter([$result->getMessageId()], static fn (?int $id): bool => $id !== null);

        foreach ($messageIds as $messageId) {
            $this->groups[$this->groupKey($group)][] = [
                'chat_id' => $result->getChatId(),
                'message_id' => $messageId,
            ];
        }
    }

    public function render(Context $context, View $view): RenderEffect
    {
        return $context->render($view);
    }

    private function groupKey(string $name): string
    {
        $conversationId = $this->context?->getConversationId() ?? 'global';

        return $conversationId . ':' . $name;
    }
}
