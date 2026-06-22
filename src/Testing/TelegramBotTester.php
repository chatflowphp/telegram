<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Testing;

use ChatFlow\Core\ClosureResolver;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\StorageException;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Telegram\Bot;
use Closure;
use PHPUnit\Framework\Assert;
use Telegram\Bot\Objects\Update;

final class TelegramBotTester
{
    private int $updateId = 1;

    private int $messageId = 1;

    public function __construct(
        private readonly Bot $bot,
        private readonly MockHttpClient $httpClient,
        private string $chatId = '123456789',
        private string $userId = '123456789',
        private string $username = 'test_user',
    ) {
    }

    public function user(string $chatId, ?string $userId = null, ?string $username = null): self
    {
        $this->chatId = $chatId;
        $this->userId = $userId ?? $chatId;
        $this->username = $username ?? 'test_user';

        return $this;
    }

    public function sendMessage(string $text, array $entities = []): self
    {
        $messageData = [
            'message_id' => $this->messageId++,
            'chat' => [
                'id' => (int) $this->chatId,
                'type' => 'private',
                'username' => $this->username,
            ],
            'from' => [
                'id' => (int) $this->userId,
                'is_bot' => false,
                'first_name' => 'Test',
                'username' => $this->username,
            ],
            'date' => time(),
            'text' => $text,
        ];

        if ($entities !== []) {
            $messageData['entities'] = $entities;
        }

        $this->bot->handle(new Update([
            'update_id' => $this->updateId++,
            'message' => $messageData,
        ]));

        return $this;
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

    public function clickButton(string $actionId, mixed $payload = null, ?int $messageId = null): self
    {
        $targetMessageId = $messageId ?? ($this->messageId > 1 ? $this->messageId - 1 : 1);
        $callbackData = $payload === null
            ? $actionId
            : (string) json_encode(['id' => $actionId, 'payload' => $payload], JSON_THROW_ON_ERROR);

        $this->bot->handle(new Update([
            'update_id' => $this->updateId++,
            'callback_query' => [
                'id' => (string) random_int(10000, 99999),
                'from' => [
                    'id' => (int) $this->userId,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => $this->username,
                ],
                'message' => [
                    'message_id' => $targetMessageId,
                    'chat' => [
                        'id' => (int) $this->chatId,
                        'type' => 'private',
                        'username' => $this->username,
                    ],
                    'date' => time(),
                    'text' => 'Callback source message',
                ],
                'data' => $callbackData,
            ],
        ]));

        return $this;
    }

    /**
     * @param string|array<mixed>|Closure $handler
     * @param array<string, mixed>        $params
     *
     * @throws LogicException
     * @throws ValidationException
     */
    public function clickSceneAction(string|array|Closure $handler, array $params = [], ?int $messageId = null): self
    {
        $methodName = ClosureResolver::resolveName($handler);

        return $this->clickButton('scene:' . $methodName, $params === [] ? null : $params, $messageId);
    }

    public function sendMedia(string $type, ?string $fileId = null, ?string $caption = null, ?string $mediaGroupId = null): self
    {
        $message = [
            'message_id' => $this->messageId++,
            'chat' => [
                'id' => (int) $this->chatId,
                'type' => 'private',
                'username' => $this->username,
            ],
            'from' => [
                'id' => (int) $this->userId,
                'is_bot' => false,
                'first_name' => 'Test',
                'username' => $this->username,
            ],
            'date' => time(),
        ];

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
            $message[$type] = [
                'file_id' => $fileId ?? 'file_1',
                'file_unique_id' => 'u1',
            ];
        }

        $this->bot->handle(new Update([
            'update_id' => $this->updateId++,
            'message' => $message,
        ]));

        return $this;
    }

    /**
     * @param list<array{type: string, file_id?: string, caption?: string}> $items
     */
    public function sendMediaGroup(array $items, string $mediaGroupId = 'album-1'): self
    {
        foreach ($items as $item) {
            $this->sendMedia(
                $item['type'],
                $item['file_id'] ?? null,
                $item['caption'] ?? null,
                $mediaGroupId
            );
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $update
     */
    public function sendRawUpdate(array $update): self
    {
        $update['update_id'] ??= $this->updateId++;
        $this->bot->handle(new Update($update));

        return $this;
    }

    public function clickCallbackData(string $callbackData, ?int $messageId = null): self
    {
        $targetMessageId = $messageId ?? ($this->messageId > 1 ? $this->messageId - 1 : 1);
        $this->bot->handle(new Update([
            'update_id' => $this->updateId++,
            'callback_query' => [
                'id' => (string) random_int(10000, 99999),
                'from' => [
                    'id' => (int) $this->userId,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => $this->username,
                ],
                'message' => [
                    'message_id' => $targetMessageId,
                    'chat' => [
                        'id' => (int) $this->chatId,
                        'type' => 'private',
                        'username' => $this->username,
                    ],
                    'date' => time(),
                    'text' => 'Callback source message',
                ],
                'data' => $callbackData,
            ],
        ]));

        return $this;
    }

    public function clear(): self
    {
        $this->httpClient->clearRequests();

        return $this;
    }

    /**
     * @return array<int, array{endpoint: string, method: string, params: array<string, mixed>}>
     */
    public function getRequests(): array
    {
        return $this->httpClient->getRequests();
    }

    public function assertSee(string $text): self
    {
        $found = false;

        foreach ($this->httpClient->getRequests() as $request) {
            $sentText = $request['params']['text'] ?? $request['params']['caption'] ?? '';
            if (is_string($sentText) && str_contains($sentText, $text)) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, "Failed asserting that text '{$text}' was sent.");

        return $this;
    }

    public function assertKeyboardHas(string $buttonText): self
    {
        foreach ($this->httpClient->getRequests() as $request) {
            $replyMarkup = $request['params']['reply_markup'] ?? null;
            if (!is_string($replyMarkup)) {
                continue;
            }

            $decoded = json_decode($replyMarkup, true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach (['inline_keyboard', 'keyboard'] as $key) {
                foreach (($decoded[$key] ?? []) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    foreach ($row as $button) {
                        if (is_array($button) && isset($button['text']) && str_contains((string) $button['text'], $buttonText)) {
                            return $this;
                        }
                    }
                }
            }
        }

        Assert::fail("Expected keyboard button '{$buttonText}' was not found.");
    }

    public function assertEndpointCalled(string $endpoint): self
    {
        foreach ($this->httpClient->getRequests() as $request) {
            if ($request['endpoint'] === $endpoint) {
                Assert::assertSame($endpoint, $request['endpoint']);

                return $this;
            }
        }

        Assert::fail("Failed asserting that endpoint '{$endpoint}' was called.");
    }

    /**
     * @throws StorageException
     */
    public function assertScene(string $sceneClass): self
    {
        $stateManager = $this->bot->getStateManager();
        Assert::assertNotNull($stateManager, 'StateManager is not configured.');

        $session = $stateManager->loadSession($this->chatId);

        Assert::assertSame($sceneClass, $session->getCurrentScene());

        return $this;
    }

    /**
     * @throws StorageException
     */
    public function assertNotInScene(): self
    {
        $stateManager = $this->bot->getStateManager();
        Assert::assertNotNull($stateManager, 'StateManager is not configured.');

        $session = $stateManager->loadSession($this->chatId);

        Assert::assertNull($session->getCurrentScene());

        return $this;
    }

    /**
     * @throws StorageException
     */
    public function assertSessionHas(string $key, mixed $expectedValue = null): self
    {
        $stateManager = $this->bot->getStateManager();
        Assert::assertNotNull($stateManager, 'StateManager is not configured.');

        $session = $stateManager->loadSession($this->chatId);
        Assert::assertTrue($session->has($key), "Expected session to have key '{$key}'.");

        if (func_num_args() > 1) {
            Assert::assertSame($expectedValue, $session->get($key));
        }

        return $this;
    }
}
