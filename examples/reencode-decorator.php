<?php

/**
 * A privacy decorator: re-encode raster originals on add().
 *
 * Phone photos carry GPS coordinates and camera serial numbers in EXIF, and
 * nobody expects an avatar upload to publish their home address. Re-encoding
 * strips the metadata block and defeats image/script polyglots — at the cost
 * of not preserving the uploaded bytes. That trade-off is the application's
 * to make, which is why this ships as an example, not a class.
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
use Rasuvaeff\Yii3Filestorage\Mime\MimeTypeDetectorInterface;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Policy\PolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Storage;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Test\MemoryRepository;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\CompositeImageProcessor;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;

require __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('gd') && !extension_loaded('imagick')) {
    echo "Skipped: neither ext-gd nor ext-imagick is loaded.\n";

    exit(0);
}

/**
 * Re-encodes raster uploads before delegating; everything else passes through
 * untouched. Only add() differs from the inner facade.
 */
final readonly class ReencodingStorage implements StorageInterface
{
    /** Same-format re-encode recipes: never resized, only decoded and re-encoded. */
    private const array RECIPES = [
        'image/jpeg' => Format::Jpeg,
        'image/png' => Format::Png,
        'image/webp' => Format::Webp,
    ];

    public function __construct(
        private StorageInterface $inner,
        private ImageProcessorInterface $processor,
        private MimeTypeDetectorInterface $mime,
        private StreamFactoryInterface $streams,
    ) {}

    public function add(
        Upload $upload,
        ?string $groupName = null,
        ?string $storeName = null,
        ?string $description = null,
        array $metadata = [],
    ): File {
        $detected = $this->mime->detect($upload);
        $format = $detected === null ? null : (self::RECIPES[$detected] ?? null);

        if ($format !== null && $this->processor->supports($detected, $format)) {
            // Preset::MAX_DIMENSION with upscale=false keeps the pixels as
            // they are; only the encoding — and with it the metadata — is new.
            $processed = $this->processor->process($upload->stream(), $detected, new Preset(
                name: 'reencoded',
                format: $format,
                width: Preset::MAX_DIMENSION,
                height: Preset::MAX_DIMENSION,
                quality: 92,
            ));
            $upload = Upload::fromStream(
                stream: $processed->stream,
                originalName: $upload->originalName,
                streamFactory: $this->streams,
            );
        }

        return $this->inner->add($upload, $groupName, $storeName, $description, $metadata);
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

    public function temporaryUrl(File $file, DateTimeImmutable $expiresAt): ?string
    {
        return $this->inner->temporaryUrl($file, $expiresAt);
    }

    public function urlFor(File $file, ?DateTimeImmutable $expiresAt = null): ?string
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

// --- demo ------------------------------------------------------------------

$psr = new Psr17Factory();
$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
};

$storage = new ReencodingStorage(
    inner: new Storage(
        stores: new StoreRegistry([new InMemoryStore('memory', $psr, $clock)]),
        repository: new MemoryRepository(),
        pathGenerator: new RandomPathGenerator(),
        mimeTypeDetector: new FinfoMimeTypeDetector(),
        idGenerator: new Uuid7IdGenerator($clock),
        policies: new PolicyRegistry(),
        deliveryPolicies: new DeliveryPolicyRegistry(),
        clock: $clock,
        defaultUrlTtl: new DateInterval('PT1H'),
    ),
    processor: CompositeImageProcessor::fromAvailable($psr),
    mime: new FinfoMimeTypeDetector(),
    streams: $psr,
);

// A PNG "upload".
if (extension_loaded('gd')) {
    $image = imagecreatetruecolor(64, 64);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 60, 60));
    ob_start();
    imagepng($image);
    $pngBytes = (string) ob_get_clean();
} else {
    $imagick = new Imagick();
    $imagick->newImage(64, 64, 'firebrick');
    $imagick->setImageFormat('PNG');
    $pngBytes = $imagick->getImageBlob();
}

$file = $storage->add(Upload::fromStream($psr->createStream($pngBytes), 'avatar.png', $psr));
echo "stored: {$file->relativePath} ({$file->size} bytes, {$file->mimeType})\n";
echo 'bytes were re-encoded: ' . var_export($storage->content($file) !== $pngBytes, true) . "\n";

// A text upload passes through byte-identical.
$text = $storage->add(Upload::fromStream($psr->createStream('just text'), 'note.txt', $psr));
echo 'text untouched: ' . var_export($storage->content($text) === 'just text', true) . "\n";
