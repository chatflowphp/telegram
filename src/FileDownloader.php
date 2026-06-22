<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

final class FileDownloader
{
    public function __construct(
        private readonly Api $api
    ) {
    }

    /**
     * @throws TelegramSDKException
     */
    public function download(string $fileId, string $destinationDir): ?string
    {
        if (!file_exists($destinationDir) && !mkdir($destinationDir, 0755, true)) {
            return null;
        }

        if (!is_dir($destinationDir)) {
            return null;
        }

        $file = $this->api->getFile(['file_id' => $fileId]);
        /** @var string|null $filePath */
        $filePath = $file->get('file_path');
        if ($filePath === null) {
            return null;
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $filename = uniqid('telegram_file_', true) . ($extension !== '' ? '.' . $extension : '');
        $fullPath = rtrim($destinationDir, '/') . '/' . $filename;

        $this->api->downloadFile($file, $fullPath);

        return $fullPath;
    }
}
