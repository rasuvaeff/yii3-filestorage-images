<?php

/**
 * Lazy derivative generation end to end: a real filesystem store in a temp
 * directory, one uploaded PNG, one preset — generated on first request,
 * reused on the second.
 *
 * Needs ext-gd or ext-imagick (exits cleanly otherwise). No server needed.
 */

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\FileSystem\FileSystemStore;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3FilestorageImages\Derivatives;
use Rasuvaeff\Yii3FilestorageImages\Fit;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Rasuvaeff\Yii3FilestorageImages\Processing\CompositeImageProcessor;
use Yiisoft\Mutex\MutexFactory;
use Yiisoft\Mutex\MutexInterface;

require __DIR__ . '/../vendor/autoload.php';

if (!extension_loaded('gd') && !extension_loaded('imagick')) {
    echo "Skipped: neither ext-gd nor ext-imagick is loaded.\n";

    exit(0);
}

$psr = new Psr17Factory();
$root = sys_get_temp_dir() . '/filestorage-images-example-' . bin2hex(random_bytes(4));
mkdir($root, 0o755, recursive: true);

// A source image to store, made with whatever extension is available.
if (extension_loaded('gd')) {
    $image = imagecreatetruecolor(400, 200);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
    ob_start();
    imagepng($image);
    $pngBytes = (string) ob_get_clean();
} else {
    $imagick = new Imagick();
    $imagick->newImage(400, 200, 'steelblue');
    $imagick->setImageFormat('PNG');
    $pngBytes = $imagick->getImageBlob();
}

// Place the original the way the storage family lays files out:
// one directory per file, `original.<ext>` inside.
$relativePath = 'common/ab/cd/example/original.png';
mkdir($root . '/common/ab/cd/example', 0o755, recursive: true);
file_put_contents($root . '/' . $relativePath, $pngBytes);

$file = Rasuvaeff\Yii3Filestorage\File::create(
    id: 'example-1',
    storeName: 'local',
    groupName: 'common',
    relativePath: $relativePath,
    originalName: 'photo.png',
    size: strlen($pngBytes),
    createdAt: new DateTimeImmutable(),
    mimeType: 'image/png',
);

// An in-process mutex is enough for a single-script example; a real
// application binds a yiisoft/mutex driver (file, db, redis).
$mutexes = new class extends MutexFactory {
    public function create(string $name): MutexInterface
    {
        return new class implements MutexInterface {
            public function acquire(int $timeout = 0): bool
            {
                return true;
            }

            public function release(): void {}
        };
    }
};

$derivatives = new Derivatives(
    stores: new StoreRegistry([new FileSystemStore(name: 'local', rootPath: $root, streamFactory: $psr)]),
    presets: new PresetRegistry([
        new Preset(name: 'thumb', format: Format::Webp, width: 100, height: 100, fit: Fit::Cover),
    ]),
    processor: CompositeImageProcessor::fromAvailable($psr),
    mutexes: $mutexes,
    deliveryPolicies: new DeliveryPolicyRegistry(),
    clock: new class implements Psr\Clock\ClockInterface {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable();
        }
    },
);

echo 'exists before:  ' . var_export($derivatives->exists($file, 'thumb'), true) . "\n";
echo 'first ensure:   generated=' . var_export($derivatives->ensure($file, 'thumb'), true) . "\n";
echo 'second ensure:  generated=' . var_export($derivatives->ensure($file, 'thumb'), true) . "\n";

$stream = $derivatives->stream($file, 'thumb');
$info = getimagesizefromstring((string) $stream);
echo 'rendition:      ' . $derivatives->descriptor('thumb')->fileName()
    . " {$info[0]}x{$info[1]} {$info['mime']}\n";
echo 'on disk:        ' . implode(', ', array_map(
    basename(...),
    glob($root . '/common/ab/cd/example/*') ?: [],
)) . "\n";

// Cleanup.
array_map(unlink(...), glob($root . '/common/ab/cd/example/*') ?: []);
