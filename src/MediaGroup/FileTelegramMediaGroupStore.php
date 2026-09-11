<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\MediaGroup;

use JsonException;
use RuntimeException;

/**
 * Keeps pending album parts as JSON files grouped per media group. Stale groups are removed
 * opportunistically on storePart() with the configured probability, never on construction.
 */
final class FileTelegramMediaGroupStore implements TelegramMediaGroupStoreInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds = 300,
        private readonly int $cleanupProbability = 5,
    ) {}

    public function storePart(string $groupKey, int $messageId, array $update): void
    {
        $this->maybeCleanupExpired();

        $dir = $this->groupDirectory($groupKey);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(\sprintf('Failed to create Telegram media group directory "%s".', $dir));
        }

        try {
            $encoded = json_encode($update, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('Telegram media group part cannot be stored: ' . $e->getMessage(), 0, $e);
        }

        if (file_put_contents($dir . '/' . $messageId . '.json', $encoded, LOCK_EX) === false) {
            throw new RuntimeException(\sprintf('Failed to write Telegram media group part %d.', $messageId));
        }
    }

    public function getParts(string $groupKey): array
    {
        $dir = $this->groupDirectory($groupKey);

        if (!is_dir($dir)) {
            return [];
        }

        $parts = [];
        $paths = glob($dir . '/*.json');

        foreach ($paths === false ? [] : $paths as $path) {
            $contents = file_get_contents($path);

            if ($contents === false) {
                continue;
            }

            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (!\is_array($decoded)) {
                continue;
            }

            $update = [];

            foreach ($decoded as $key => $value) {
                $update[(string) $key] = $value;
            }

            $parts[] = ['message_id' => (int) basename($path, '.json'), 'update' => $update];
        }

        usort($parts, static fn(array $a, array $b): int => $a['message_id'] <=> $b['message_id']);

        return $parts;
    }

    public function claim(string $groupKey): bool
    {
        $dir = $this->groupDirectory($groupKey);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return false;
        }

        // Creating the marker with the exclusive "x" mode is atomic on POSIX filesystems: exactly
        // one concurrent request succeeds.
        $handle = @fopen($dir . '/.leader', 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    public function deleteGroup(string $groupKey): void
    {
        $this->removeGroupDirectory($this->groupDirectory($groupKey));
    }

    public function cleanupExpired(): void
    {
        if ($this->ttlSeconds <= 0 || !is_dir($this->directory)) {
            return;
        }

        $expiresBefore = time() - $this->ttlSeconds;
        $dirs = glob(rtrim($this->directory, '/') . '/*', GLOB_ONLYDIR);

        foreach ($dirs === false ? [] : $dirs as $dir) {
            clearstatcache(true, $dir);
            $modifiedAt = $this->latestModifiedAt($dir) ?? filemtime($dir);

            if ($modifiedAt === false || $modifiedAt > $expiresBefore) {
                continue;
            }

            $this->removeGroupDirectory($dir);
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

    private function removeGroupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $paths = glob($dir . '/{*.json,.leader}', GLOB_BRACE);

        foreach ($paths === false ? [] : $paths as $path) {
            @unlink($path);
        }

        @rmdir($dir);
    }

    private function latestModifiedAt(string $dir): ?int
    {
        $latest = null;
        $paths = glob($dir . '/*.json');

        foreach ($paths === false ? [] : $paths as $path) {
            clearstatcache(true, $path);
            $modifiedAt = filemtime($path);

            if ($modifiedAt === false) {
                continue;
            }

            $latest = $latest === null ? $modifiedAt : max($latest, $modifiedAt);
        }

        return $latest;
    }

    private function groupDirectory(string $groupKey): string
    {
        $safeKey = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $groupKey);

        return rtrim($this->directory, '/') . '/' . $safeKey;
    }
}
