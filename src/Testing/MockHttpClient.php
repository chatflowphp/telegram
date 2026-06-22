<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Testing;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Telegram\Bot\HttpClients\HttpClientInterface;

final class MockHttpClient implements HttpClientInterface
{
    /** @var array<int, array{endpoint: string, method: string, params: array<string, mixed>}> */
    private array $requests = [];

    /** @var array<string, array{code: int, description: string}> */
    private array $failures = [];

    private int $timeOut = 30;

    private int $connectTimeOut = 10;

    /**
     * @param array<array-key, mixed> $headers
     * @param array<array-key, mixed> $options
     */
    public function send(
        string $url,
        string $method,
        array $headers = [],
        array $options = [],
        bool $isAsyncRequest = false
    ): ResponseInterface|PromiseInterface {
        if (isset($options['sink']) && is_string($options['sink'])) {
            $dir = dirname($options['sink']);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($options['sink'], 'dummy file content');

            return new Response(200, [], 'dummy file content');
        }

        $path = parse_url($url, PHP_URL_PATH);
        $endpoint = basename(is_string($path) ? $path : '');
        $params = $this->normalizeParams($options);

        $this->requests[] = [
            'endpoint' => $endpoint,
            'method' => $method,
            'params' => $params,
        ];

        if (isset($this->failures[$endpoint])) {
            $failure = $this->failures[$endpoint];
            $body = json_encode([
                'ok' => false,
                'error_code' => $failure['code'],
                'description' => $failure['description'],
            ], JSON_THROW_ON_ERROR);

            return new Response($failure['code'], ['Content-Type' => 'application/json'], $body);
        }

        $result = $this->generateMockResult($endpoint, $params);
        $body = json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);

        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    /**
     * @return array<int, array{endpoint: string, method: string, params: array<string, mixed>}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    public function clearRequests(): void
    {
        $this->requests = [];
    }

    public function failEndpoint(string $endpoint, string $description = 'Bad Request: mocked failure', int $code = 400): void
    {
        $this->failures[$endpoint] = [
            'code' => $code,
            'description' => $description,
        ];
    }

    public function clearFailures(): void
    {
        $this->failures = [];
    }

    public function getTimeOut(): int
    {
        return $this->timeOut;
    }

    public function setTimeOut(int $timeOut): static
    {
        $this->timeOut = $timeOut;

        return $this;
    }

    public function getConnectTimeOut(): int
    {
        return $this->connectTimeOut;
    }

    public function setConnectTimeOut(int $connectTimeOut): static
    {
        $this->connectTimeOut = $connectTimeOut;

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function normalizeParams(array $options): array
    {
        if (isset($options['multipart']) && is_array($options['multipart'])) {
            $params = [];
            foreach ($options['multipart'] as $item) {
                if (!is_array($item) || !isset($item['name']) || !is_string($item['name']) || !array_key_exists('contents', $item)) {
                    continue;
                }

                $contents = $item['contents'];
                $params[$item['name']] = is_resource($contents) || is_object($contents)
                    ? '[BINARY DATA]'
                    : $contents;
            }

            return $params;
        }

        foreach (['form_params', 'json', 'query'] as $key) {
            $payload = $options[$key] ?? null;
            if (is_array($payload)) {
                return $this->normalizeAssocParams($payload);
            }
        }

        return [];
    }

    /**
     * @param array<mixed, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function normalizeAssocParams(array $params): array
    {
        $normalized = [];

        foreach ($params as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function generateMockResult(string $endpoint, array $params): mixed
    {
        $messageMethods = [
            'sendMessage', 'sendPhoto', 'sendVideo', 'sendAudio', 'sendDocument', 'sendAnimation',
            'editMessageText', 'editMessageCaption', 'editMessageMedia', 'deleteMessage',
            'answerCallbackQuery',
        ];

        if ($endpoint === 'sendMediaGroup') {
            $chatId = isset($params['chat_id']) && is_numeric($params['chat_id']) ? (int) $params['chat_id'] : 12345;
            $media = [];
            if (isset($params['media']) && is_string($params['media'])) {
                $decoded = json_decode($params['media'], true);
                if (is_array($decoded)) {
                    $media = $decoded;
                }
            }

            return array_map(static fn (mixed $item, int $index): array => [
                'message_id' => count($media) + $index + 1,
                'date' => time(),
                'chat' => [
                    'id' => $chatId,
                    'type' => 'private',
                ],
                'caption' => is_array($item) ? (string) ($item['caption'] ?? '') : '',
            ], $media, array_keys($media));
        }

        if (in_array($endpoint, $messageMethods, true)) {
            return [
                'message_id' => count($this->requests),
                'date' => time(),
                'chat' => [
                    'id' => isset($params['chat_id']) && is_numeric($params['chat_id']) ? (int) $params['chat_id'] : 12345,
                    'type' => 'private',
                ],
                'text' => (string) ($params['text'] ?? $params['caption'] ?? ''),
            ];
        }

        if ($endpoint === 'getFile') {
            return [
                'file_id' => $params['file_id'] ?? 'file_1',
                'file_path' => 'mock/file.txt',
            ];
        }

        if ($endpoint === 'getMe') {
            return [
                'id' => 1,
                'is_bot' => true,
                'first_name' => 'MockBot',
                'username' => 'mock_bot',
            ];
        }

        return true;
    }
}
