<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;

/**
 * A seekable stream that honours `read()` but always returns at most one byte
 * — PSR-7 explicitly permits short reads, and a header reader must loop
 * rather than assume the requested length arrived.
 *
 * @internal
 */
final class TrickleStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(
        private readonly string $body,
        private readonly int $chunkSize = 1,
    ) {}

    #[Override]
    public function __toString(): string
    {
        return $this->body;
    }

    #[Override]
    public function close(): void {}

    #[Override]
    public function detach()
    {
        return null;
    }

    #[Override]
    public function getSize(): ?int
    {
        return strlen($this->body);
    }

    #[Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[Override]
    public function eof(): bool
    {
        return $this->position >= strlen($this->body);
    }

    #[Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->position = match ($whence) {
            \SEEK_CUR => $this->position + $offset,
            \SEEK_END => strlen($this->body) + $offset,
            default => $offset,
        };
    }

    #[Override]
    public function rewind(): void
    {
        $this->position = 0;
    }

    #[Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[Override]
    public function write(string $string): int
    {
        throw new \RuntimeException('Not writable');
    }

    #[Override]
    public function read(int $length): string
    {
        $chunk = substr($this->body, $this->position, min($length, $this->chunkSize));
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[Override]
    public function getContents(): string
    {
        $rest = substr($this->body, $this->position);
        $this->position = strlen($this->body);

        return $rest;
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
