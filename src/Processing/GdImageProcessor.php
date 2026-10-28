<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use GdImage;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;

/**
 * GD-backed processor.
 *
 * What GD gives up against Imagick, spelled out because it is invisible in a
 * green test run: no EXIF auto-orientation (a phone photo shot sideways stays
 * sideways), and an animated GIF is flattened to its first frame. What it
 * gives back: it strips metadata by construction — a decoded-and-re-encoded
 * image has no EXIF block to carry GPS coordinates in.
 *
 * @api
 */
final readonly class GdImageProcessor implements ImageProcessorInterface
{
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
        if (!\extension_loaded('gd')) {
            return false;
        }

        $sourceBit = $this->typeBit($sourceMediaType);
        $outputBit = $this->typeBit($output->mediaType());

        return $sourceBit !== null
            && $outputBit !== null
            && (imagetypes() & $sourceBit) === $sourceBit
            && (imagetypes() & $outputBit) === $outputBit;
    }

    /**
     * Media type => the IMG_* capability bit `imagetypes()` reports. A method
     * rather than a constant map: the IMG_* constants exist only when ext-gd
     * is loaded, and a constant initializer referencing them would make the
     * whole class fatal on a runtime that merely asked `supports()`. Presence
     * of the bit is checked per call — GD's format support is decided when the
     * extension is compiled, not when this package is installed.
     */
    private function typeBit(string $mediaType): ?int
    {
        return match ($mediaType) {
            'image/jpeg' => IMG_JPG,
            'image/png' => IMG_PNG,
            'image/webp' => IMG_WEBP,
            'image/gif' => IMG_GIF,
            'image/avif' => IMG_AVIF,
            default => null,
        };
    }

    #[Override]
    public function process(StreamInterface $source, string $sourceMediaType, Preset $preset): ProcessedImage
    {
        if (!$this->supports($sourceMediaType, $preset->format)) {
            throw new UnsupportedSourceException(
                "GD cannot process \"{$sourceMediaType}\" into \"{$preset->format->value}\"",
            );
        }

        $bytes = SourceGuard::readBounded($source, $this->maxSourceBytes);
        [$sourceWidth, $sourceHeight] = SourceGuard::dimensions($bytes, $this->maxSourcePixels);

        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof GdImage) {
            throw new ProcessingFailedException('GD could not decode the source image');
        }

        $target = TargetSize::calculate($sourceWidth, $sourceHeight, $preset);
        $canvas = $this->canvas($target, $preset->format);

        if (!imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            $target->sourceX,
            $target->sourceY,
            $target->width,
            $target->height,
            $target->sourceWidth,
            $target->sourceHeight,
        )) {
            throw new ProcessingFailedException('GD could not resample the source image');
        }

        return new ProcessedImage(
            stream: $this->encode($canvas, $preset),
            width: $target->width,
            height: $target->height,
        );
    }

    /**
     * @throws ProcessingFailedException
     */
    private function canvas(TargetSize $target, Format $format): GdImage
    {
        $canvas = imagecreatetruecolor($target->width, $target->height);
        if (!$canvas instanceof GdImage) {
            throw new ProcessingFailedException('GD could not allocate the output canvas');
        }

        if ($format->supportsAlpha()) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $background = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        } else {
            // JPEG has no alpha channel; a transparent source composites onto
            // white rather than onto whatever the encoder felt like.
            $background = imagecolorallocate($canvas, 255, 255, 255);
        }
        if ($background === false || !imagefill($canvas, 0, 0, $background)) {
            throw new ProcessingFailedException('GD could not prepare the output canvas');
        }

        return $canvas;
    }

    /**
     * @throws ProcessingFailedException
     */
    private function encode(GdImage $canvas, Preset $preset): StreamInterface
    {
        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new ProcessingFailedException('Could not open a temporary stream for the encoded image');
        }

        $written = match ($preset->format) {
            Format::Jpeg => imagejpeg($canvas, $resource, quality: $preset->quality),
            // PNG is lossless; quality maps onto zlib effort: a higher
            // quality is more effort and therefore a *smaller* file — the
            // same direction as the lossy formats and as Imagick's
            // setImageCompressionQuality(), so one preset behaves alike
            // across processors.
            Format::Png => imagepng($canvas, $resource, quality: intdiv($preset->quality * 9, 100)),
            Format::Webp => imagewebp($canvas, $resource, quality: $preset->quality),
            Format::Gif => imagegif($canvas, $resource),
            Format::Avif => imageavif($canvas, $resource, quality: $preset->quality),
        };
        if (!$written) {
            fclose($resource);

            throw new ProcessingFailedException("GD could not encode the image as \"{$preset->format->value}\"");
        }

        rewind($resource);

        return $this->streams->createStreamFromResource($resource);
    }
}
