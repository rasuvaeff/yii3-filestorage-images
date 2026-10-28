<?php

/**
 * A watermark decorator: stamp raster originals on add().
 *
 * A watermark is a property of the *original*, not of a derivative — which is
 * exactly why it does not belong in a preset. The "rendition = pure function
 * of bytes + preset version" invariant is what makes deduplicated,
 * blob-scoped derivatives safe to share; baking an owner-specific mark into
 * one preset would break it. The place for a watermark is therefore upload
 * time: the stored bytes are watermarked once, and every derivative of them
 * carries the mark by construction.
 *
 * Needs ext-gd or ext-imagick (exits cleanly otherwise). No server needed.
 */

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Id\Uuid7IdGenerator;
use Rasuvaeff\Yii3Filestorage\Mime\FinfoMimeTypeDetector;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageImages\Preset;

require __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('gd') && !extension_loaded('imagick')) {
    echo "Skipped: neither ext-gd nor ext-imagick is loaded.\n";

    exit(0);
}

/**
 * Stamps "© example.test" into the bottom-right corner of raster uploads
 * before delegating; non-raster uploads pass through untouched. Only add()
 * differs from the inner facade — the same shape the re-encoding example
 * uses, because both are "pre-process at upload" recipes.
 */
final readonly class WatermarkingStorage implements StorageInterface
{
    public function __construct(
        private StorageInterface $inner,
        private StreamFactoryInterface $streams,
    ) {}

    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File {
        $bytes = $upload->stream()->getContents();
        $stamped = $this->stamp($bytes) ?? $bytes;

        return $this->inner->add(
            upload: Upload::fromStream(
                $this->streams->createStream($stamped),
                $upload->originalName,
                $this->streams,
                clientMediaTypeHint: $upload->clientMediaTypeHint,
            ),
            groupName: $groupName,
            storeName: $storeName,
            description: $description,
            metadata: $metadata,
        );
    }

    /**
     * GD is enough for a corner label: decode, allocate the alpha color,
     * write the string into the bottom-right, re-encode as PNG. Null means
     * "not a decodable raster, leave the bytes alone" — the decorator must
     * not make uploads fail that the policy accepted.
     *
     * @return non-empty-string|null
     */
    private function stamp(string $bytes): ?string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $label = '© example.test';
        $font = 3;
        $box = imagefontwidth($font) * strlen($label);
        $color = imagecolorallocatealpha($source, 255, 255, 255, 96);
        imagestring($source, $font, $width - $box - 8, $height - imagefontheight($font) - 8, $label, $color);

        ob_start();
        imagepng($source);
        /** @var non-empty-string $png */
        $png = (string) ob_get_clean();
        imagedestroy($source);

        return $png;
    }

    public function find(string $id): ?File
    {
        return $this->inner->find($id);
    }

    public function remove(string $id): bool
    {
        return $this->inner->remove($id);
    }

    public function exists(File $file): bool
    {
        return $this->inner->exists($file);
    }

    public function url(File $file): ?string
    {
        return $this->inner->url($file);
    }

    public function temporaryUrl(File $file, \DateTimeImmutable $expiresAt): ?string
    {
        return $this->inner->temporaryUrl($file, $expiresAt);
    }

    public function urlFor(File $file, ?\DateTimeImmutable $expiresAt = null): ?string
    {
        return $this->inner->urlFor($file, $expiresAt);
    }

    public function stream(File $file): ?StreamInterface
    {
        return $this->inner->stream($file);
    }

    public function content(File $file): ?string
    {
        return $this->inner->content($file);
    }
}

final class FixedClock implements ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $now) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

$factory = new Psr17Factory();
$storage = new WatermarkingStorage(
    inner: new Storage(
        stores: new StoreRegistry([new InMemoryStore('memory', $factory)]),
        repository: new MemoryRepository(),
        pathGenerator: new RandomPathGenerator(),
        mimeTypeDetector: new FinfoMimeTypeDetector(),
        idGenerator: new Uuid7IdGenerator(new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'))),
        policies: new PolicyRegistry(),
        deliveryPolicies: new DeliveryPolicyRegistry(),
        clock: new FixedClock(new \DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        defaultUrlTtl: new DateInterval('PT1H'),
    ),
    streams: $factory,
);

// A small solid PNG as the upload stand-in.
$canvas = imagecreatetruecolor(320, 200);
imagefill($canvas, 0, 0, imagecolorallocate($canvas, 40, 60, 90));
ob_start();
imagepng($canvas);
$pngBytes = (string) ob_get_clean();
imagedestroy($canvas);

$file = $storage->add(Upload::fromStream($factory->createStream($pngBytes), 'photo.png', $factory));
echo "stored: {$file->relativePath} ({$file->size} bytes, {$file->mimeType})\n";
echo 'bytes were watermarked: ' . var_export($storage->content($file) !== $pngBytes, true) . "\n";

$text = $storage->add(Upload::fromStream($factory->createStream('just text'), 'note.txt', $factory));
echo 'non-image untouched: ' . var_export($storage->content($text) === 'just text', true) . "\n";
echo "\nEvery derivative of the watermarked original carries the mark by\n";
echo "construction - presets never need to know about it.\n";
