<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeAwareStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeObject;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A derivative-capable store double. Core's `Test\InMemoryStore` deliberately
 * does not implement the derivative capability, and these tests need to
 * inspect writes anyway.
 *
 * @internal
 */
final class DerivativeAwareMemoryStore implements DerivativeAwareStoreInterface
{
    /** @var array<string, string> Original relative path => bytes. */
    private array $objects = [];

    /** @var array<string, string> Derivative key => bytes. */
    private array $derivatives = [];

    /** @var list<string> Derivative keys in write order. */
    public array $derivativeWrites = [];

    public function __construct(
        private readonly string $name = 'memory',
        private readonly Psr17Factory $streams = new Psr17Factory(),
    ) {}

    public function setObject(string $relativePath, string $bytes): void
    {
        $this->objects[$relativePath] = $bytes;
    }

    public function putDerivative(File $file, DerivativeDescriptor $derivative, string $bytes): void
    {
        $this->derivatives[$this->derivativeKey($file, $derivative)] = $bytes;
    }

    public function derivativeBytes(File $file, DerivativeDescriptor $derivative): ?string
    {
        return $this->derivatives[$this->derivativeKey($file, $derivative)] ?? null;
    }

    #[Override]
    public function name(): string
    {
        return $this->name === '' ? 'memory' : $this->name;
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        throw new LogicException('These tests never ingest through the store');
    }

    #[Override]
    public function delete(File $file): void
    {
        unset($this->objects[$file->relativePath]);
        foreach (array_keys($this->derivatives) as $key) {
            if (str_starts_with($key, $file->relativePath . '::')) {
                unset($this->derivatives[$key]);
            }
        }
    }

    #[Override]
    public function exists(File $file): bool
    {
        return isset($this->objects[$file->relativePath]);
    }

    #[Override]
    public function size(File $file): ?int
    {
        $bytes = $this->objects[$file->relativePath] ?? null;

        return $bytes === null ? null : max(0, \strlen($bytes));
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->exists($file) ? Fixtures::now() : null;
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        $bytes = $this->objects[$file->relativePath] ?? null;

        return $bytes === null ? null : $this->streams->createStream($bytes);
    }

    #[Override]
    public function hasDerivative(File $file, DerivativeDescriptor $derivative): bool
    {
        return isset($this->derivatives[$this->derivativeKey($file, $derivative)]);
    }

    #[Override]
    public function derivativeStream(File $file, DerivativeDescriptor $derivative): ?StreamInterface
    {
        $bytes = $this->derivativeBytes($file, $derivative);

        return $bytes === null ? null : $this->streams->createStream($bytes);
    }

    #[Override]
    public function writeDerivative(
        File $file,
        DerivativeDescriptor $derivative,
        StreamInterface $contents,
        int $maxBytes = 0,
    ): DerivativeObject {
        $bytes = (string) $contents;
        if ($maxBytes > 0 && \strlen($bytes) > $maxBytes) {
            throw new UploadTooLargeException("Derivative exceeds {$maxBytes} bytes");
        }

        $key = $this->derivativeKey($file, $derivative);
        $this->derivatives[$key] = $bytes;
        $this->derivativeWrites[] = $key;

        return new DerivativeObject(
            relativePath: \dirname($file->relativePath) . '/' . $derivative->fileName(),
            size: max(0, \strlen($bytes)),
            mediaType: $derivative->mediaType,
        );
    }

    private function derivativeKey(File $file, DerivativeDescriptor $derivative): string
    {
        return $file->relativePath . '::' . $derivative->fileName();
    }
}
