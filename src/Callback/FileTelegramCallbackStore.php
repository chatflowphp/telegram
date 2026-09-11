<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

use ChatFlow\Support\SerializableValueValidator;
use JsonException;
use RuntimeException;

/**
 * Stores callback payloads as JSON files named by token. Storing a token again rewrites the file
 * and restarts its TTL, so a button that is still being rendered never expires. Expired files are
 * removed opportunistically on put() with the configured probability, never on construction.
 */
final class FileTelegramCallbackStore implements TelegramCallbackStoreInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds = 604800,
        private readonly int $cleanupProbability = 2,
    ) {}

    public function put(string $token, array $payload): void
    {
        SerializableValueValidator::assertSerializable($payload, 'telegram callback payload');
        $this->ensureDirectory();
        $this->maybeCleanupExpired();

        $path = $this->path($token);

        try {
            $encoded = json_encode(['created_at' => time(), 'payload' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('Telegram callback payload cannot be stored: ' . $e->getMessage(), 0, $e);
        }

        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new RuntimeException(\sprintf('Failed to write Telegram callback payload "%s".', $path));
        }
    }

    public function get(string $token): ?array
    {
        $path = $this->path($token);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            @unlink($path);

            return null;
        }

        $record = $this->extract($decoded);

        if ($record === null) {
            @unlink($path);
        }

        return $record;
    }

    public function delete(string $token): void
    {
        $path = $this->path($token);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function cleanupExpired(): void
    {
        if ($this->ttlSeconds <= 0 || !is_dir($this->directory)) {
            return;
        }

        $expiresBefore = time() - $this->ttlSeconds;
        $paths = glob(rtrim($this->directory, '/') . '/*.json');

        foreach ($paths === false ? [] : $paths as $path) {
            clearstatcache(true, $path);
            $modifiedAt = filemtime($path);

            if ($modifiedAt === false || $modifiedAt > $expiresBefore) {
                continue;
            }

            @unlink($path);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new RuntimeException(\sprintf('Failed to create Telegram callback store directory "%s".', $this->directory));
        }
    }

    private function maybeCleanupExpired(): void
    {
        if ($this->cleanupProbability <= 0) {
            return;
        }

        if ($this->cleanupProbability >= 100 || random_int(1, 100) <= $this->cleanupProbability) {
            $this->cleanupExpired();
        }
    }

    /**
     * @return array{id: string, payload: mixed}|null
     */
    private function extract(mixed $decoded): ?array
    {
        if (!\is_array($decoded)) {
            return null;
        }

        $createdAt = $decoded['created_at'] ?? null;

        if ($this->ttlSeconds > 0 && \is_int($createdAt) && $createdAt < time() - $this->ttlSeconds) {
            return null;
        }

        $payload = $decoded['payload'] ?? null;

        if (!\is_array($payload) || !isset($payload['id']) || !\is_string($payload['id'])) {
            return null;
        }

        return ['id' => $payload['id'], 'payload' => $payload['payload'] ?? null];
    }

    private function path(string $token): string
    {
        $safeToken = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $token);

        return rtrim($this->directory, '/') . '/' . $safeToken . '.json';
    }
}
