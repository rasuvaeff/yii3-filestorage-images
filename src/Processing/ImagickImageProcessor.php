<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use Imagick;
use ImagickException;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;

/**
 * Imagick-backed processor.
 *
 * Two behaviours GD cannot offer, both deliberate: EXIF orientation is applied
 * before the geometry is computed, so a phone photo shot sideways comes out
 * upright; and metadata is stripped explicitly ({@see Imagick::stripImage()}),
 * because unlike GD, ImageMagick preserves profiles across a re-encode — and a
 * thumbnail that still carries the original's GPS coordinates is a privacy
 * leak wearing a smaller size.
 *
 * @api
 */
final readonly class ImagickImageProcessor implements ImageProcessorInterface
{
    /**
     * Media type => ImageMagick format name for `queryFormats()`.
     */
    private const array FORMATS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
        'image/gif' => 'GIF',
        'image/avif' => 'AVIF',
    ];

    /**
     * @param int<0, max> $maxSourceBytes Zero means no cap.
     * @param int<0, max> $maxSourcePixels Zero means no cap.
     */
    public function __construct(
        private StreamFactoryInterface $streams,
        private int $maxSourceBytes = 67_108_864,
        private int $maxSourcePixels = 40_000_000,
    ) {}

    #[Override]
    public function supports(string $sourceMediaType, Format $output): bool
    {
        if (!\extension_loaded('imagick')) {
            return false;
        }

        $source = self::FORMATS[$sourceMediaType] ?? null;
        $target = self::FORMATS[$output->mediaType()] ?? null;

        return $source !== null
            && $target !== null
            && Imagick::queryFormats($source) !== []
            && Imagick::queryFormats($target) !== [];
    }

    #[Override]
    public function process(StreamInterface $source, string $sourceMediaType, Preset $preset): ProcessedImage
    {
        if (!$this->supports($sourceMediaType, $preset->format)) {
            throw new UnsupportedSourceException(
                "Imagick cannot process \"{$sourceMediaType}\" into \"{$preset->format->value}\"",
            );
        }

        $bytes = SourceGuard::readBounded($source, $this->maxSourceBytes);
        // The pixel guard runs on the header before ImageMagick decodes —
        // readImageBlob() allocates by declared dimensions, exactly the
        // decompression bomb the guard exists for.
        SourceGuard::dimensions($bytes, $this->maxSourcePixels);

        // The coder comes from the detected media type, never from
        // ImageMagick's own sniffing of the bytes, and `[0]` selects the
        // first frame at read time: a derivative is a still, and decoding
        // every frame of an animation before keeping one is what let 30
        // frames of 1 MP through a 2 MP cap (review 1.0 A4). The selector
        // only works through the filename — `readImageBlob()`'s second
        // argument ignores it.
        $selector = self::FORMATS[$sourceMediaType] . ':source[0]';
        [$frameWidth, $frameHeight] = $this->firstFrame($bytes, $selector);

        $image = new Imagick();

        try {
            try {
                $this->read($image, $bytes, $selector, $frameWidth, $frameHeight);

                // Orient first, then measure: rotation swaps width and height,
                // and the geometry has to see the image the viewer will.
                $this->orient($image);

                $sourceWidth = $image->getImageWidth();
                $sourceHeight = $image->getImageHeight();
                if ($sourceWidth < 1 || $sourceHeight < 1) {
                    throw new ProcessingFailedException('Imagick reported empty source dimensions');
                }

                $target = TargetSize::calculate($sourceWidth, $sourceHeight, $preset);

                $image->cropImage($target->sourceWidth, $target->sourceHeight, $target->sourceX, $target->sourceY);
                // Forget the pre-crop canvas geometry, or GIF/PNG offsets
                // reappear in the output.
                $image->setImagePage(0, 0, 0, 0);
                $image->resizeImage($target->width, $target->height, Imagick::FILTER_LANCZOS, 1.0);

                if (!$preset->format->supportsAlpha()) {
                    $image->setImageBackgroundColor('white');
                    $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                }

                $image->stripImage();
                $image->setImageFormat(self::FORMATS[$preset->format->mediaType()]);
                $image->setImageCompressionQuality($preset->quality);

                $blob = $image->getImageBlob();
            } catch (ImagickException $exception) {
                throw ImagickFailure::from($exception, 'Imagick could not process the image');
            }

            if ($blob === '') {
                throw new ProcessingFailedException('Imagick produced an empty encoded image');
            }

            return new ProcessedImage(
                stream: $this->streams->createStream($blob),
                width: $target->width,
                height: $target->height,
            );
        } finally {
            $image->clear();
        }
    }

    /**
     * The first frame's own size, from a header-only ping, checked against the
     * cap. `getimagesize()` reports a GIF's logical screen, while the decoder
     * allocates each frame at its descriptor size: a 1x1 screen over a
     * 4000x4000 frame passed the header guard. Both the frame and the canvas
     * it sits on are checked.
     *
     * @return array{positive-int, positive-int}
     *
     * @throws ProcessingFailedException
     * @throws UnsupportedSourceException
     */
    private function firstFrame(string $bytes, string $selector): array
    {
        $ping = new Imagick();

        try {
            $ping->setFilename($selector);
            $ping->pingImageBlob($bytes);
            $width = $ping->getImageWidth();
            $height = $ping->getImageHeight();
            $page = $ping->getImagePage();
        } catch (ImagickException $exception) {
            throw ImagickFailure::from($exception, 'Imagick could not process the image');
        } finally {
            $ping->clear();
        }

        if ($width < 1 || $height < 1) {
            throw new ProcessingFailedException('Imagick reported empty source dimensions');
        }

        foreach ([[$width, $height, 'frame'], [$page['width'], $page['height'], 'canvas']] as [$w, $h, $what]) {
            if ($this->maxSourcePixels > 0 && $w * $h > $this->maxSourcePixels) {
                throw new UnsupportedSourceException(
                    "Source {$what} is {$w}x{$h} = " . ($w * $h)
                    . " pixels, over the {$this->maxSourcePixels}-pixel processing cap",
                );
            }
        }

        return [$width, $height];
    }

    /**
     * Reads the one frame with ImageMagick's own limits narrowed to what the
     * ping declared: no wider or taller than that frame, no second image in
     * the list. Defence in depth for a coder whose read disagrees with its
     * ping. The limits are process-wide state, so they are only ever lowered,
     * and put back as soon as the read returns — the application's own
     * Imagick use, and the next file, must not inherit them. (The area and
     * memory limits are not used: past them ImageMagick 7 moves the pixel
     * cache to disk instead of refusing.)
     *
     * @param positive-int $width
     * @param positive-int $height
     *
     * @throws ImagickException
     */
    private function read(Imagick $image, string $bytes, string $selector, int $width, int $height): void
    {
        $narrowed = [
            Imagick::RESOURCETYPE_WIDTH => $width,
            Imagick::RESOURCETYPE_HEIGHT => $height,
        ];
        // Newer than width/height: absent from older imagick builds.
        if (\defined('Imagick::RESOURCETYPE_LISTLENGTH')) {
            // Two, not one: ImageMagick refuses a list whose length reaches
            // the limit, so one would refuse the single frame itself.
            $narrowed[Imagick::RESOURCETYPE_LISTLENGTH] = 2;
        }
        $previous = [];
        foreach ($narrowed as $type => $limit) {
            $current = Imagick::getResourceLimit($type);
            $previous[$type] = $current;
            if ($limit < $current) {
                Imagick::setResourceLimit($type, $limit);
            }
        }

        try {
            $image->setFilename($selector);
            $image->readImageBlob($bytes);
            // The coder prefix stays on the handle and would win over
            // setImageFormat() at encode time: every rendition came out in
            // the source's format.
            $image->setFilename('');
        } finally {
            foreach ($previous as $type => $limit) {
                Imagick::setResourceLimit($type, $limit >= (float) \PHP_INT_MAX ? \PHP_INT_MAX : (int) $limit);
            }
        }
    }

    /**
     * Applies the EXIF orientation by hand — `autoOrientImage()` exists only
     * in some imagick builds, and a processor whose behaviour depends on how
     * the extension was compiled would pass tests on one machine and rotate
     * nothing on another. These primitives have been in every build for years.
     *
     * @throws ImagickException
     */
    private function orient(Imagick $image): void
    {
        $orientation = $image->getImageOrientation();

        match ($orientation) {
            Imagick::ORIENTATION_TOPRIGHT => $image->flopImage(),
            Imagick::ORIENTATION_BOTTOMRIGHT => $image->rotateImage('#000', 180.0),
            Imagick::ORIENTATION_BOTTOMLEFT => [$image->flopImage(), $image->rotateImage('#000', 180.0)],
            Imagick::ORIENTATION_LEFTTOP => [$image->flopImage(), $image->rotateImage('#000', -90.0)],
            Imagick::ORIENTATION_RIGHTTOP => $image->rotateImage('#000', 90.0),
            Imagick::ORIENTATION_RIGHTBOTTOM => [$image->flopImage(), $image->rotateImage('#000', 90.0)],
            Imagick::ORIENTATION_LEFTBOTTOM => $image->rotateImage('#000', -90.0),
            default => null,
        };

        if ($orientation !== Imagick::ORIENTATION_UNDEFINED) {
            $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        }
    }
}
