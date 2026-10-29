<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;

/**
 * The first page of a PDF, rendered as the preset's image — the poster every
 * document listing wants.
 *
 * The "different processor keyed by source MIME" shape the processing contract
 * was drawn around: `ImageProcessorInterface::supports()` already dispatches
 * on the source media type, so a PDF preset needs no new plumbing — the
 * composite picks this processor for `application/pdf` exactly as the raster
 * ones are picked for `image/*`.
 *
 * Imagick-only on purpose: GD cannot read PDFs, and rendering one is
 * ghostscript's job anyway. `supports()` answers from the format registry
 * (`Imagick::queryFormats('PDF')`) — a registry entry without a working
 * ghostscript binary is possible (a stripped distro), and such a runtime
 * learns it at generation time: `process()` fails with
 * `ProcessingFailedException` naming the delegate, the same loud failure a
 * broken AVIF encoder produces.
 *
 * Resolution is chosen, not guessed: the page geometry is *pinged* and the
 * render DPI is the largest (≤ 150) that keeps the raster under the pixel
 * cap. Ordering, honestly: the byte cap is the only guard that runs before
 * ImageMagick parses the document — the ping itself is an ImageMagick parse
 * (metadata-only, no rasterisation), and a page whose 72-DPI geometry is
 * already over the cap is refused before a single pixel is allocated.
 *
 * @api
 */
final readonly class PdfPreviewProcessor implements ImageProcessorInterface
{
    private const int MAX_RENDER_DPI = 150;

    private const int BASE_DPI = 72;

    private const array ENCODABLE = [
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
        if ($sourceMediaType !== 'application/pdf' || !\extension_loaded('imagick')) {
            return false;
        }

        if (!isset(self::ENCODABLE[$output->mediaType()])) {
            return false;
        }

        return \Imagick::queryFormats('PDF') !== []
            && \Imagick::queryFormats(self::ENCODABLE[$output->mediaType()]) !== [];
    }

    #[Override]
    public function process(StreamInterface $source, string $sourceMediaType, Preset $preset): ProcessedImage
    {
        if (!$this->supports($sourceMediaType, $preset->format)) {
            throw new UnsupportedSourceException(
                "PDF preview cannot process \"{$sourceMediaType}\" into \"{$preset->format->value}\"",
            );
        }

        $bytes = SourceGuard::readBounded($source, $this->maxSourceBytes);

        $dpi = $this->renderDpi($bytes);

        // Page zero, selected at READ time: the blob API takes no page
        // selector, and reading the whole document first ("then keep frame
        // 0") makes a hundred-page PDF cost a hundred renders before one is
        // kept. The spooled file with the `[0]` selector limits the
        // ghostscript invocation to the first page.
        $spool = tempnam(sys_get_temp_dir(), 'filestorage-pdf');
        \assert($spool !== false);
        file_put_contents($spool, $bytes);

        $image = new \Imagick();

        try {
            try {
                // Density before the read: ImageMagick rasterises PDFs at the
                // resolution in effect when the document is opened.
                $image->setResolution($dpi, $dpi);
                $image->readImage($spool . '[0]');

                $sourceWidth = $image->getImageWidth();
                $sourceHeight = $image->getImageHeight();
                if ($sourceWidth < 1 || $sourceHeight < 1) {
                    throw new ProcessingFailedException('Imagick rendered an empty PDF page');
                }

                $target = TargetSize::calculate($sourceWidth, $sourceHeight, $preset);

                $image->cropImage($target->sourceWidth, $target->sourceHeight, $target->sourceX, $target->sourceY);
                $image->setImagePage(0, 0, 0, 0);
                $image->resizeImage($target->width, $target->height, \Imagick::FILTER_LANCZOS, 1.0);

                if (!$preset->format->supportsAlpha()) {
                    $image->setImageBackgroundColor('white');
                    $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                }

                $image->stripImage();
                $image->setImageFormat(self::ENCODABLE[$preset->format->mediaType()]);
                $image->setImageCompressionQuality($preset->quality);

                $blob = $image->getImageBlob();
            } catch (\ImagickException $exception) {
                throw new ProcessingFailedException(
                    "Imagick could not render the PDF: {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            if ($blob === '') {
                throw new ProcessingFailedException('Imagick produced an empty PDF preview');
            }

            return new ProcessedImage(
                stream: $this->streams->createStream($blob),
                width: $target->width,
                height: $target->height,
            );
        } finally {
            $image->clear();
            @unlink($spool);
        }
    }

    /**
     * The render DPI: page geometry comes from `pingImageBlob()` — a metadata
     * read at the 72-DPI baseline, nothing rasterised — and the DPI is the
     * largest (up to 150) whose raster stays under the pixel cap.
     *
     * @param non-empty-string $bytes
     *
     * @return float
     */
    private function renderDpi(string $bytes): float
    {
        $ping = new \Imagick();

        try {
            $ping->pingImageBlob($bytes);
            $pageWidth = $ping->getImageWidth();
            $pageHeight = $ping->getImageHeight();
        } catch (\ImagickException $exception) {
            throw new ProcessingFailedException(
                "Imagick could not read the PDF page geometry: {$exception->getMessage()}",
                previous: $exception,
            );
        } finally {
            $ping->clear();
        }

        if ($pageWidth < 1 || $pageHeight < 1) {
            throw new ProcessingFailedException('The PDF reports an empty page');
        }

        $baselinePixels = $pageWidth * $pageHeight;
        if ($this->maxSourcePixels > 0 && $baselinePixels > $this->maxSourcePixels) {
            // Unsupported, not failed — the raster pixel guards throw the
            // same verdict, and `filestorage:warm` counts it a skip, not a
            // broken rendition.
            throw new UnsupportedSourceException(sprintf(
                'PDF page is %dx%d points — over the %d pixel cap even at the 72-DPI baseline',
                $pageWidth,
                $pageHeight,
                $this->maxSourcePixels,
            ));
        }

        if ($this->maxSourcePixels === 0) {
            return (float) self::MAX_RENDER_DPI;
        }

        // DPI scales both axes linearly, so rendered pixels scale with its
        // square: d = 72 * sqrt(cap / baseline) is the largest density whose
        // raster fits the cap, and the baseline check above guarantees it is
        // at least 72.
        $affordable = (float) self::BASE_DPI * sqrt($this->maxSourcePixels / $baselinePixels);

        return (float) min(self::MAX_RENDER_DPI, (int) $affordable);
    }
}
