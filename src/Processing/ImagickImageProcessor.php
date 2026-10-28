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

        $image = new Imagick();

        try {
            try {
                $image->readImageBlob($bytes);

                // One frame only: a derivative is a still rendition, and the
                // first frame is what every player shows as the poster anyway.
                if ($image->getNumberImages() > 1) {
                    $image->setIteratorIndex(0);
                    $image = $image->getImage();
                }

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
                throw new ProcessingFailedException(
                    "Imagick could not process the image: {$exception->getMessage()}",
                    previous: $exception,
                );
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
