<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use DateTimeImmutable;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;

/**
 * Stands in for what `rasuvaeff/yii3-filestorage-web` binds: a route through
 * the application that serves bytes behind a signed token. Records the last
 * minted arguments so tests can assert what travelled into the URL.
 *
 * @internal
 */
final class SignedProxyUrlGenerator implements ProxyUrlGeneratorInterface
{
    public ?string $lastVariant = null;

    public ?DateTimeImmutable $lastExpiresAt = null;

    #[Override]
    public function url(File $file, DateTimeImmutable $expiresAt): ?string
    {
        $this->lastExpiresAt = $expiresAt;

        return "https://app.example.test/files/original/{$file->id}";
    }

    #[Override]
    public function variantUrl(File $file, string $variant, DateTimeImmutable $expiresAt): ?string
    {
        $this->lastVariant = $variant;
        $this->lastExpiresAt = $expiresAt;

        return "https://app.example.test/files/{$file->id}/{$variant}/{$expiresAt->getTimestamp()}";
    }
}
