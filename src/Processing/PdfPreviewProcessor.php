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
 * broken AVIF encoder produces. The registry cannot see a `policy.xml` that
 * forbids the PDF coder either (Debian's default); that refusal surfaces from
 * `process()` as `UnsupportedSourceException` — a verdict about the
 * installation, not the file.
 *
 * Ghostscript parses untrusted PostScript. Keep it current, keep ImageMagick's
 * default `-dSAFER` delegate, and prefer forbidding the PDF coder in
 * `policy.xml` over this processor if PDFs are not needed.
 *
 * Resolution is chosen, not guessed: the page geometry is *pinged* and the
 * render DPI is the largest (≤ 150) that keeps the raster under the pixel
 * cap. Ordering, honestly: the byte cap is the only guard that runs before
 * ImageMagick parses the document — the ping itself is an ImageMagick parse
 * (metadata-only, no rasterisation), and a page whose 72-DPI geometry is
 * already over the cap is refused before a single pixel is allocated. The
 * ping is ImageMagick's reading of the file's text, not the page ghostscript
 * renders, so the render is bounded on its own: ghostscript draws onto a
 * fixed media of the pinged size (`pdf:fit-page`), and a raster that still
 * comes out larger is refused.
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

        // Page zero, selected at READ time: the blob API takes no page
        // selector, and reading the whole document first ("then keep frame
        // 0") makes a hundred-page PDF cost a hundred renders before one is
        // kept. The spooled file with the `[0]` selector limits the
        // ghostscript invocation — and the geometry ping — to the first page.
        $spool = tempnam(sys_get_temp_dir(), 'filestorage-pdf');
        if ($spool === false || file_put_contents($spool, $bytes) !== \strlen($bytes)) {
            if ($spool !== false) {
                @unlink($spool);
            }

            throw ProcessingFailedException::transient('Could not spool the PDF to a temporary file');
        }

        $image = new \Imagick();

        try {
            [$dpi, $pageWidth, $pageHeight] = $this->renderGeometry($spool);

            try {
                // Density before the read: ImageMagick rasterises PDFs at the
                // resolution in effect when the document is opened.
                $image->setResolution($dpi, $dpi);
                // The ping is ImageMagick's own scan of the file, not the page
                // ghostscript renders: an indirect `/MediaBox`, a box inside a
                // compressed object stream or a `/UserUnit` ping as something
                // small while the page is enormous (review 1.0 pass 2, N1).
                // `pdf:fit-page` makes ghostscript render onto a fixed media
                // of the pinged size (`-dFIXEDMEDIA -dPDFFitPage`), scaling
                // whatever the page really is into it — the raster is bounded
                // by the geometry the DPI was chosen for, not by the document.
                $image->setOption('pdf:fit-page', "{$pageWidth}x{$pageHeight}");
                $image->readImage($spool . '[0]');

                $sourceWidth = $image->getImageWidth();
                $sourceHeight = $image->getImageHeight();
                if ($sourceWidth < 1 || $sourceHeight < 1) {
                    throw new ProcessingFailedException('Imagick rendered an empty PDF page');
                }

                $this->assertWithin($sourceWidth, $sourceHeight, $pageWidth, $pageHeight, $dpi);

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
                throw ImagickFailure::from($exception, 'Imagick could not render the PDF');
            }

            if ($blob === '') {
                throw ProcessingFailedException::transient('Imagick produced an empty PDF preview');
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
     * The render DPI and the page geometry it was chosen for: the geometry
     * comes from a ping of page `[0]` — a metadata read at the 72-DPI
     * baseline, nothing rasterised — and the DPI is the largest (up to 150)
     * whose raster stays under the pixel cap. ImageMagick's PDF coder takes
     * the page box from a scan of the file's raw text, so the ping reports
     * the *largest* direct box in the document (measured, review 1.0 #13) and
     * misses boxes it cannot read at all (pass 2, N1). It is not an upper
     * bound on its own: `process()` makes it one with `pdf:fit-page` and
     * checks the raster afterwards.
     *
     * @return array{float, positive-int, positive-int}
     */
    private function renderGeometry(string $spool): array
    {
        $ping = new \Imagick();

        try {
            $ping->pingImage($spool . '[0]');
            $pageWidth = $ping->getImageWidth();
            $pageHeight = $ping->getImageHeight();
        } catch (\ImagickException $exception) {
            throw ImagickFailure::from($exception, 'Imagick could not read the PDF page geometry');
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
            return [(float) self::MAX_RENDER_DPI, $pageWidth, $pageHeight];
        }

        // DPI scales both axes linearly, so rendered pixels scale with its
        // square: d = 72 * sqrt(cap / baseline) is the largest density whose
        // raster fits the cap, and the baseline check above guarantees it is
        // at least 72.
        $affordable = (float) self::BASE_DPI * sqrt($this->maxSourcePixels / $baselinePixels);

        return [(float) min(self::MAX_RENDER_DPI, (int) $affordable), $pageWidth, $pageHeight];
    }

    /**
     * The hard check after the render: the raster is no larger than the
     * pinged page at the chosen DPI (one pixel of rounding per side). A
     * ghostscript that ignored the fixed media — an ImageMagick without
     * `pdf:fit-page` support — is caught here, after the allocation but
     * before anything is resized, encoded or cached as a rendition.
     */
    private function assertWithin(int $width, int $height, int $pageWidth, int $pageHeight, float $dpi): void
    {
        $maxWidth = (int) ceil((float) $pageWidth * $dpi / (float) self::BASE_DPI) + 1;
        $maxHeight = (int) ceil((float) $pageHeight * $dpi / (float) self::BASE_DPI) + 1;

        if ($width > $maxWidth || $height > $maxHeight) {
            throw new UnsupportedSourceException(sprintf(
                'PDF page rendered at %dx%d pixels, larger than the %dx%d its pinged geometry allows',
                $width,
                $height,
                $maxWidth,
                $maxHeight,
            ));
        }
    }
}
