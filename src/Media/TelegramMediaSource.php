<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Media;

use InvalidArgumentException;
use Telegram\Bot\FileUpload\InputFile;

final class TelegramMediaSource
{
    private function __construct(
        private readonly string $kind,
        private readonly string $value,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Telegram media source value must be non-empty.');
        }
    }

    public static function fileId(string $fileId): self
    {
        return new self('file_id', $fileId);
    }

    public static function localPath(string $path): self
    {
        return new self('local_path', $path);
    }

    public static function url(string $url): self
    {
        return new self('url', $url);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function isLocalPath(): bool
    {
        return $this->kind === 'local_path';
    }

    public function toSingleApiValue(): string|InputFile
    {
        if ($this->isLocalPath()) {
            return InputFile::create($this->value);
        }

        return $this->value;
    }

    public function toMediaGroupApiValue(): string
    {
        if ($this->isLocalPath()) {
            throw new InvalidArgumentException('Local paths in sendMediaGroup require multipart attach:// handling and are not supported by this publisher.');
        }

        return $this->value;
    }
}
