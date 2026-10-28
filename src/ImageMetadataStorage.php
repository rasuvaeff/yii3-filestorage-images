<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\UploadPolicy;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Records image dimensions into the file's metadata at upload time.
 *
 * `File::$metadata` reserved room for exactly this (plan NG1), and the upload
 * policy already parses the image header to enforce pixel caps — then throws
 * the numbers away. This decorator keeps them: an image consumer learns
 * `width`/`height` from the row instead of decoding the object.
 *
 * Detection is the policy's own recipe: a bounded header-prefix read
 * (never a decode — `getimagesizefromstring()` ships with ext-standard and
 * parses headers only), and anything it cannot establish is simply not
 * recorded — a decorator must not make uploads fail that the policy accepted.
 *
 * @api
 */
final readonly class ImageMetadataStorage implements StorageInterface
{
    public function __construct(
        private StorageInterface $storage,
    ) {}

    /**
     * @param non-empty-string|null $groupName
     * @param non-empty-string|null $storeName
     * @param array<array-key, mixed> $metadata
     */
    #[Override]
    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File {
        return $this->storage->add(
            upload: $upload,
            groupName: $groupName,
            storeName: $storeName,
            description: $description,
            metadata: [...$metadata, ...$this->dimensions($upload)],
        );
    }

    #[Override]
    public function find(string $id): ?File
    {
        return $this->storage->find($id);
    }

    #[Override]
    public function remove(string $id): bool
    {
        return $this->storage->remove($id);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->storage->exists($file);
    }

    #[Override]
    public function url(File $file): ?string
    {
        return $this->storage->url($file);
    }

    #[Override]
    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt): ?string
    {
        return $this->storage->temporaryUrl($file, $expiresAt);
    }

    #[Override]
    public function urlFor(File $file, ?DateTimeImmutable $expiresAt = null): ?string
    {
        return $this->storage->urlFor($file, $expiresAt);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->storage->stream($file);
    }

    #[Override]
    public function content(File $file): ?string
    {
        return $this->storage->content($file);
    }

    /**
     * The header-only dimensions, or nothing. The stream is rewound after the
     * prefix read — the storage behind this decorator reads the same bytes.
     *
     * @return array{width: int, height: int}|array<never, never>
     */
    private function dimensions(Upload $upload): array
    {
        $stream = $upload->stream();
        $header = $stream->read(UploadPolicy::MAX_IMAGE_HEADER_BYTES);
        $stream->rewind();

        // getimagesizefromstring() answers false or a real pair — a 0x0
        // result is not something it produces for parseable input.
        $size = @getimagesizefromstring($header);
        if ($size === false) {
            return [];
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }
}
