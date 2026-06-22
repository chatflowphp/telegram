<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\MediaGroup;

final class FileTelegramMediaGroupStore implements TelegramMediaGroupStoreInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttlSeconds = 300,
        private readonly int $cleanupProbability = 100,
    ) {
        $this->ensureDirectory();
        $this->maybeCleanupExpired();
    }

    public function storePart(string $groupKey, int $messageId, array $update): void
    {
        $this->maybeCleanupExpired();

        $dir = $this->groupDirectory($groupKey);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $dir . '/' . $messageId . '.json',
            json_encode($update, JSON_THROW_ON_ERROR),
            LOCK_EX
        );
    }

    public function getParts(string $groupKey): array
    {
        $dir = $this->groupDirectory($groupKey);
        if (!is_dir($dir)) {
            return [];
        }

        $parts = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            $decoded = json_decode($contents, true);
            if (!is_array($decoded)) {
                continue;
            }

            $messageId = (int) basename($path, '.json');
            $parts[] = [
                'message_id' => $messageId,
                'update' => $decoded,
            ];
        }

        usort($parts, static fn (array $a, array $b): int => $a['message_id'] <=> $b['message_id']);

        return $parts;
    }

    public function deleteGroup(string $groupKey): void
    {
        $dir = $this->groupDirectory($groupKey);
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*.json') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($dir);
    }

    public function cleanupExpired(): void
    {
        if ($this->ttlSeconds <= 0) {
            return;
        }

        $expiresBefore = time() - $this->ttlSeconds;
        foreach (glob(rtrim($this->directory, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $latestModifiedAt = $this->latestModifiedAt($dir);
            $modifiedAt = $latestModifiedAt ?? filemtime($dir);
            if ($modifiedAt === false || $modifiedAt > $expiresBefore) {
                continue;
            }

            foreach (glob($dir . '/*.json') ?: [] as $path) {
                @unlink($path);
            }

            @rmdir($dir);
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

    private function latestModifiedAt(string $dir): ?int
    {
        $latest = null;
        foreach (glob($dir . '/*.json') ?: [] as $path) {
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
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $groupKey) ?? $groupKey;

        return rtrim($this->directory, '/') . '/' . $safeKey;
    }
}
