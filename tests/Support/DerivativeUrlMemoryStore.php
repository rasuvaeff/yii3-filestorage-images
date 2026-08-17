<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeObject;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeUrlProviderInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * URL-capable variant, by composition — the URL support is a separate class
 * so tests can model "store without URLs" accurately with the base double.
 *
 * @internal
 */
final readonly class DerivativeUrlMemoryStore implements DerivativeUrlProviderInterface
{
    public function __construct(
        public DerivativeAwareMemoryStore $inner = new DerivativeAwareMemoryStore(),
        private bool $publicUrls = true,
        private bool $temporaryUrls = true,
    ) {}

    #[Override]
    public function publicDerivativeUrl(File $file, DerivativeDescriptor $derivative): ?string
    {
        return $this->publicUrls
            ? "https://cdn.example.test/{$file->relativePath}/../{$derivative->fileName()}"
            : null;
    }

    #[Override]
    public function temporaryDerivativeUrl(
        File $file,
        DerivativeDescriptor $derivative,
        DateTimeImmutable $expiresAt,
    ): ?string {
        return $this->temporaryUrls
            ? "https://signed.example.test/{$derivative->fileName()}?expires={$expiresAt->getTimestamp()}"
            : null;
    }

    #[Override]
    public function name(): string
    {
        return $this->inner->name();
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        return $this->inner->write($upload, $groupName, $pathGenerator, $mediaType, $maxBytes);
    }

    #[Override]
    public function delete(File $file): void
    {
        $this->inner->delete($file);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->inner->exists($file);
    }

    #[Override]
    public function size(File $file): ?int
    {
        return $this->inner->size($file);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->inner->lastModified($file);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->inner->stream($file);
    }

    #[Override]
    public function hasDerivative(File $file, DerivativeDescriptor $derivative): bool
    {
        return $this->inner->hasDerivative($file, $derivative);
    }

    #[Override]
    public function derivativeStream(File $file, DerivativeDescriptor $derivative): ?StreamInterface
    {
        return $this->inner->derivativeStream($file, $derivative);
    }

    #[Override]
    public function writeDerivative(
        File $file,
        DerivativeDescriptor $derivative,
        StreamInterface $contents,
        int $maxBytes = 0,
    ): DerivativeObject {
        return $this->inner->writeDerivative($file, $derivative, $contents, $maxBytes);
    }
}
