<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\File;

/**
 * @internal
 */
final class Fixtures
{
    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(datetime: '2026-01-01T00:00:00.000000+00:00');
    }

    /**
     * @param non-empty-string $id
     * @param non-empty-string $storeName
     * @param non-empty-string|null $mimeType
     * @param int<0, max> $size
     * @param non-empty-string $groupName
     */
    public static function file(
        string $id = 'file-1',
        string $storeName = 'memory',
        ?string $mimeType = 'image/png',
        int $size = 1024,
        string $groupName = 'common',
        ?string $relativePath = null,
    ): File {
        return File::create(
            id: $id,
            storeName: $storeName,
            groupName: $groupName,
            relativePath: $relativePath ?? "common/ab/cd/{$id}/original.png",
            originalName: 'photo.png',
            size: $size,
            createdAt: self::now(),
            mimeType: $mimeType,
        );
    }
}
