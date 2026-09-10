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

/**
 * Best-effort cleanup of message groups published with TelegramPublisher: the next
 * beginGroup() deletes the messages tracked under the same name.
 *
 * Tracked message ids are stored in the conversation session when a request context is bound, so
 * cleanup works across webhook requests; without a conversation they are kept in memory.
 */
final class TelegramScreenManager
{
    private const EXTENSION = 'telegram.screens';

    /**
     * @var array<string, list<array{chat_id: string|int, message_id: int}>>
     */
    private array $memory = [];

    public function __construct(
        private readonly TelegramPublisher $publisher,
        private readonly ?Context $context = null,
        private ?string $activeGroup = null,
    ) {}

    public function forContext(Context $context): self
    {
        $clone = new self($this->publisher, $context, $this->activeGroup);
        $clone->memory = &$this->memory;

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
        foreach ($this->load($name) as $message) {
            try {
                $this->publisher->deleteMessage($message['chat_id'], $message['message_id']);
            } catch (Throwable) {
                // Screen cleanup must never break the user-facing flow.
            }
        }

        $this->store($name, []);
    }

    public function track(TelegramDeliveryResult|TelegramDeliveryGroupResult $result, ?string $group = null): void
    {
        $group ??= $this->activeGroup;

        if ($group === null) {
            return;
        }

        $messageIds = $result instanceof TelegramDeliveryGroupResult
            ? $result->getMessageIds()
            : ($result->getMessageId() === null ? [] : [$result->getMessageId()]);

        $messages = $this->load($group);

        foreach ($messageIds as $messageId) {
            $messages[] = ['chat_id' => $result->getChatId(), 'message_id' => $messageId];
        }

        $this->store($group, $messages);
    }

    /**
     * @return list<array{chat_id: string|int, message_id: int}>
     */
    public function tracked(string $group): array
    {
        return $this->load($group);
    }

    public function render(Context $context, View $view): RenderEffect
    {
        return $context->render($view);
    }

    /**
     * @return list<array{chat_id: string|int, message_id: int}>
     */
    private function load(string $group): array
    {
        $raw = $this->context !== null && $this->context->hasConversation()
            ? ($this->context->session()->getExtension(self::EXTENSION)[$group] ?? [])
            : ($this->memory[$this->memoryKey($group)] ?? []);

        if (!\is_array($raw)) {
            return [];
        }

        $messages = [];

        foreach ($raw as $entry) {
            if (!\is_array($entry) || !isset($entry['chat_id'], $entry['message_id']) || !\is_int($entry['message_id'])) {
                continue;
            }

            $chatId = $entry['chat_id'];

            if (!\is_int($chatId) && !\is_string($chatId)) {
                continue;
            }

            $messages[] = ['chat_id' => $chatId, 'message_id' => $entry['message_id']];
        }

        return $messages;
    }

    /**
     * @param list<array{chat_id: string|int, message_id: int}> $messages
     */
    private function store(string $group, array $messages): void
    {
        if ($this->context !== null && $this->context->hasConversation()) {
            $session = $this->context->session();
            $groups = $session->getExtension(self::EXTENSION);

            if ($messages === []) {
                unset($groups[$group]);
            } else {
                $groups[$group] = $messages;
            }

            $session->setExtension(self::EXTENSION, $groups);

            return;
        }

        if ($messages === []) {
            unset($this->memory[$this->memoryKey($group)]);

            return;
        }

        $this->memory[$this->memoryKey($group)] = $messages;
    }

    private function memoryKey(string $group): string
    {
        return ($this->context?->getConversationId() ?? 'global') . ':' . $group;
    }
}
