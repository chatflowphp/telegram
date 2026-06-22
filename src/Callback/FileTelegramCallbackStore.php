<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Callback;

use ChatFlow\Support\SerializableValueValidator;

final class FileTelegramCallbackStore implements TelegramCallbackStoreInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds = 604800,
        private readonly int $cleanupProbability = 100,
    ) {
        $this->ensureDirectory();
        $this->maybeCleanupExpired();
    }

    public function put(array $payload): string
    {
        SerializableValueValidator::assertSerializable($payload, 'telegram callback payload');
        $this->maybeCleanupExpired();

        do {
            $token = bin2hex(random_bytes(8));
            $path = $this->path($token);
        } while (is_file($path));

        $encoded = json_encode([
            'created_at' => time(),
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR);
        file_put_contents($path, $encoded, LOCK_EX);

        return $token;
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

        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['id']) || !is_string($decoded['id'])) {
            $decoded = $this->extractVersionedPayload($decoded);
        }

        if ($decoded === null) {
            @unlink($path);

            return null;
        }

        return [
            'id' => $decoded['id'],
            'payload' => $decoded['payload'] ?? null,
        ];
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
        if ($this->ttlSeconds <= 0) {
            return;
        }

        $expiresBefore = time() - $this->ttlSeconds;
        foreach (glob(rtrim($this->directory, '/') . '/*.json') ?: [] as $path) {
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
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
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
     * @param mixed $decoded
     *
     * @return array{id: string, payload: mixed}|null
     */
    private function extractVersionedPayload(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $createdAt = $decoded['created_at'] ?? null;
        if ($this->ttlSeconds > 0 && is_int($createdAt) && $createdAt < time() - $this->ttlSeconds) {
            return null;
        }

        $payload = $decoded['payload'] ?? null;
        if (!is_array($payload) || !isset($payload['id']) || !is_string($payload['id'])) {
            return null;
        }

        return [
            'id' => $payload['id'],
            'payload' => $payload['payload'] ?? null,
        ];
    }

    private function path(string $token): string
    {
        $safeToken = preg_replace('/[^a-zA-Z0-9_-]/', '_', $token) ?? $token;

        return rtrim($this->directory, '/') . '/' . $safeToken . '.json';
    }
}
