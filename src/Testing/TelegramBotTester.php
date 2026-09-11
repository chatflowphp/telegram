<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Testing;

use ChatFlow\Core\ClosureResolver;
use ChatFlow\Core\Result;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Scene\BaseScene;
use ChatFlow\Scene\Conversation;
use ChatFlow\Scene\RootScene;
use ChatFlow\Telegram\Bot;
use ChatFlow\View\Action;
use Closure;
use PHPUnit\Framework\Assert;
use Telegram\Bot\Objects\Update;

/**
 * Drives a Bot with synthetic Telegram updates and asserts on the requests it sends through
 * MockHttpClient and on the conversation state.
 */
final class TelegramBotTester
{
    private int $updateId = 1;

    private int $messageId = 1;

    private ?Result $lastResult = null;

    public function __construct(
        private readonly Bot $bot,
        private readonly MockHttpClient $httpClient,
        private string $chatId = '123456789',
        private string $userId = '123456789',
        private string $username = 'test_user',
    ) {}

    public function user(string $chatId, ?string $userId = null, ?string $username = null): self
    {
        $this->chatId = $chatId;
        $this->userId = $userId ?? $chatId;
        $this->username = $username ?? 'test_user';

        return $this;
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    public function sendMessage(string $text, array $entities = []): self
    {
        $messageData = $this->message(['text' => $text]);

        if ($entities !== []) {
            $messageData['entities'] = $entities;
        }

        return $this->dispatch(['message' => $messageData]);
    }

    public function sendCommand(string $command): self
    {
        $command = str_starts_with($command, '/') ? $command : '/' . $command;

        return $this->sendMessage($command, [[
            'type' => 'bot_command',
            'offset' => 0,
            'length' => mb_strlen($command),
        ]]);
    }

    /**
     * Presses an inline button. Payloads are encoded and signed exactly like rendered buttons.
     */
    public function clickButton(string $actionId, mixed $payload = null, ?int $messageId = null): self
    {
        return $this->clickCallbackData(
            $this->bot->getCallbackEncoder()->encode(new Action($actionId, $actionId, $payload)),
            $messageId,
        );
    }

    /**
     * Presses a scene action button (`scene:onMethod`).
     *
     * @param string|array{0: object|string, 1: string}|Closure $handler
     * @param array<string, mixed> $params
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function clickSceneAction(string|array|Closure $handler, array $params = [], ?int $messageId = null): self
    {
        $method = ClosureResolver::resolveName($handler);

        return $this->clickButton(BaseScene::ACTION_PREFIX . $method, $params === [] ? null : $params, $messageId);
    }

    public function clickCallbackData(string $callbackData, ?int $messageId = null): self
    {
        $targetMessageId = $messageId ?? max(1, $this->messageId - 1);

        return $this->dispatch([
            'callback_query' => [
                'id' => (string) random_int(10000, 99999),
                'from' => $this->from(),
                'message' => $this->message(['message_id' => $targetMessageId, 'text' => 'Callback source message'], false),
                'data' => $callbackData,
            ],
        ]);
    }

    public function sendMedia(string $type, ?string $fileId = null, ?string $caption = null, ?string $mediaGroupId = null): self
    {
        $message = $this->message();

        if ($caption !== null) {
            $message['caption'] = $caption;
        }

        if ($mediaGroupId !== null) {
            $message['media_group_id'] = $mediaGroupId;
        }

        if ($type === 'photo') {
            $message['photo'] = [
                ['file_id' => $fileId ?? 'photo_small', 'file_unique_id' => 'u1', 'width' => 100, 'height' => 100],
                ['file_id' => ($fileId ?? 'photo_large') . '_large', 'file_unique_id' => 'u2', 'width' => 500, 'height' => 500],
            ];
        } else {
            $message[$type] = ['file_id' => $fileId ?? 'file_1', 'file_unique_id' => 'u1'];
        }

        return $this->dispatch(['message' => $message]);
    }

    /**
     * @param list<array{type: string, file_id?: string, caption?: string}> $items
     */
    public function sendMediaGroup(array $items, string $mediaGroupId = 'album-1'): self
    {
        foreach ($items as $item) {
            $this->sendMedia($item['type'], $item['file_id'] ?? null, $item['caption'] ?? null, $mediaGroupId);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $update
     */
    public function sendRawUpdate(array $update): self
    {
        return $this->dispatch($update);
    }

    public function clear(): self
    {
        $this->httpClient->clearRequests();

        return $this;
    }

    /**
     * @return list<array{endpoint: string, method: string, params: array<string, mixed>}>
     */
    public function getRequests(): array
    {
        return $this->httpClient->getRequests();
    }

    public function getLastResult(): ?Result
    {
        return $this->lastResult;
    }

    /**
     * The current conversation of the active user, restored from storage.
     */
    public function conversation(): Conversation
    {
        return $this->bot->getConversations()->resume($this->chatId);
    }

    // -- assertions ----------------------------------------------------------------------------

    public function assertSee(string $text): self
    {
        Assert::assertTrue($this->wasSent($text), \sprintf("Failed asserting that text '%s' was sent.", $text));

        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertFalse($this->wasSent($text), \sprintf("Failed asserting that text '%s' was not sent.", $text));

        return $this;
    }

    public function assertKeyboardHas(string $buttonText): self
    {
        Assert::assertTrue($this->keyboardHas($buttonText), \sprintf("Expected keyboard button '%s' was not found.", $buttonText));

        return $this;
    }

    public function assertEndpointCalled(string $endpoint): self
    {
        Assert::assertContains($endpoint, $this->endpoints(), \sprintf("Failed asserting that endpoint '%s' was called.", $endpoint));

        return $this;
    }

    public function assertEndpointNotCalled(string $endpoint): self
    {
        Assert::assertNotContains($endpoint, $this->endpoints(), \sprintf("Failed asserting that endpoint '%s' was not called.", $endpoint));

        return $this;
    }

    private function wasSent(string $text): bool
    {
        foreach ($this->httpClient->getRequests() as $request) {
            $sent = $request['params']['text'] ?? $request['params']['caption'] ?? '';

            if (\is_string($sent) && str_contains($sent, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function endpoints(): array
    {
        return array_column($this->httpClient->getRequests(), 'endpoint');
    }

    private function keyboardHas(string $buttonText): bool
    {
        foreach ($this->httpClient->getRequests() as $request) {
            $replyMarkup = $request['params']['reply_markup'] ?? null;

            if (!\is_string($replyMarkup)) {
                continue;
            }

            $decoded = json_decode($replyMarkup, true);

            if (!\is_array($decoded)) {
                continue;
            }

            foreach (['inline_keyboard', 'keyboard'] as $key) {
                $rows = $decoded[$key] ?? [];

                foreach (\is_array($rows) ? $rows : [] as $row) {
                    foreach (\is_array($row) ? $row : [] as $button) {
                        if (\is_array($button) && \is_string($button['text'] ?? null) && str_contains($button['text'], $buttonText)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param string $scene Scene class or scene id.
     */
    public function assertScene(string $scene): self
    {
        Assert::assertSame($this->bot->getScenes()->resolveId($scene), $this->conversation()->getCurrentScene());

        return $this;
    }

    public function assertNotInScene(): self
    {
        Assert::assertSame(RootScene::ID, $this->conversation()->getCurrentScene());

        return $this;
    }

    /**
     * @param string $scene Scene class or scene id.
     */
    public function assertScenePending(string $scene): self
    {
        $pending = $this->bot->getConversations()->getPending($this->chatId);

        Assert::assertNotNull($pending, 'No scene transition is pending.');
        Assert::assertSame('enter', $pending['action']);
        Assert::assertSame($this->bot->getScenes()->resolveId($scene), $pending['scene']);

        return $this;
    }

    public function assertNoScenePending(): self
    {
        Assert::assertNull($this->bot->getConversations()->getPending($this->chatId));

        return $this;
    }

    public function assertSessionHas(string $key, mixed $expectedValue = null): self
    {
        $session = $this->conversation()->getContext();
        Assert::assertTrue($session->has($key), \sprintf("Expected session to have key '%s'.", $key));

        if (\func_num_args() > 1) {
            Assert::assertSame($expectedValue, $session->get($key));
        }

        return $this;
    }

    public function assertSessionMissing(string $key): self
    {
        Assert::assertFalse($this->conversation()->getContext()->has($key), \sprintf("Expected session not to have key '%s'.", $key));

        return $this;
    }

    public function assertResult(string $status, ?string $message = null): self
    {
        Assert::assertNotNull($this->lastResult, 'No update has been handled yet.');
        Assert::assertSame($status, $this->lastResult->getStatus());

        if ($message !== null) {
            Assert::assertSame($message, $this->lastResult->getMessage());
        }

        return $this;
    }

    // -- internals -----------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $update
     */
    private function dispatch(array $update): self
    {
        $update['update_id'] ??= $this->updateId++;
        $this->lastResult = $this->bot->handle(new Update($update));

        return $this;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function message(array $overrides = [], bool $withFrom = true): array
    {
        $message = [
            'message_id' => $this->messageId++,
            'chat' => ['id' => (int) $this->chatId, 'type' => 'private', 'username' => $this->username],
            'date' => time(),
        ];

        if ($withFrom) {
            $message['from'] = $this->from();
        }

        return array_merge($message, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function from(): array
    {
        return ['id' => (int) $this->userId, 'is_bot' => false, 'first_name' => 'Test', 'username' => $this->username];
    }
}
