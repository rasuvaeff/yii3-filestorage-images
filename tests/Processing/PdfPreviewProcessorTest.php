<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\PdfPreviewProcessor;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Pdf;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PdfPreviewProcessor::class)]
final class PdfPreviewProcessorTest
{
    public function rendersTheFirstPageIntoThePresetBox(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }

        $processed = $this->processor()->process(
            $this->stream(Pdf::page(200, 100)),
            'application/pdf',
            new Preset(name: 'pdf-thumb', format: Format::Webp, width: 100),
        );

        // Contain fit of a 200x100 page into a 100-wide box: 100x50.
        Assert::same([$processed->width, $processed->height], [100, 50]);
        // The ENCODED blob carries the same geometry — a resize the in-memory
        // report does not know about is a lie the decoder would expose.
        $bytes = (string) $processed->stream;
        $encoded = \getimagesizefromstring($bytes);
        Assert::same([$encoded[0] ?? null, $encoded[1] ?? null], [100, 50]);
        Assert::same($encoded['mime'] ?? null, 'image/webp');
    }

    public function refusesOtherSources(): void
    {
        $processor = $this->processor();

        Assert::false($processor->supports('image/png', Format::Webp));

        Expect::exception(UnsupportedSourceException::class)->withMessageContaining('cannot process');

        $processor->process($this->stream('not a pdf at all'), 'image/png', new Preset(
            name: 'x',
            format: Format::Webp,
            width: 100,
        ));
    }

    /**
     * A page whose 72-DPI geometry is already over the pixel cap is refused
     * before a single render pixel is allocated.
     */
    public function anOversizedPageIsRefusedBeforeRendering(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }

        $processor = new PdfPreviewProcessor(new Psr17Factory(), maxSourceBytes: 1_048_576, maxSourcePixels: 100_000);

        Expect::exception(ProcessingFailedException::class)->withMessageContaining('pixel cap');

        $processor->process($this->stream(Pdf::page(1000, 2000)), 'application/pdf', new Preset(
            name: 'x',
            format: Format::Webp,
            width: 100,
        ));
    }

    /** Only the first page matters — a smaller second page must not leak in. */
    public function aTwoPageDocumentUsesTheFirstPage(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }

        $processed = $this->processor()->process(
            $this->stream(Pdf::twoPages(200, 100)),
            'application/pdf',
            new Preset(name: 'x', format: Format::Png, width: 1000, upscale: false),
        );

        // 200x100 points at the default 150 DPI is ~416x208, upscaled to
        // nothing: the raster is the output.
        Assert::true($processed->width > 400 && $processed->width < 430, "first page not used alone: {$processed->width}");
        Assert::true($processed->height > 200 && $processed->height < 215, "first page not used alone: {$processed->height}");
    }

    /** One zero side is as empty as two — the message names the page, not the crash. */
    public function aPageWithOneZeroSideIsRefusedAsEmpty(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }
        if (getenv('INFECTION')) {
            // A ghostscript fatal abort sends SIGTERM to its process group,
            // which under Infection's initial run takes the whole Testo
            // suite down with exit 143. The guard these tests prove runs in
            // every other context; Infection's initial pass skips the crash.
            throw new SkipTest('a crashing gs aborts the Infection process group');
        }


        // Ghostscript refuses a zero MediaBox side itself (as a geometry
        // error); whatever of the two fires, it is a processing error.
        Expect::exception(ProcessingFailedException::class);

        $this->processor()->process(
            $this->stream(Pdf::page(0, 100)),
            'application/pdf',
            new Preset(name: 'x', format: Format::Webp, width: 100),
        );
    }

    /** The cap boundary is inclusive: a page exactly at it renders. */
    public function aPageExactlyAtTheCapRenders(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }

        // Baseline 200x100 = 20,000 px; the cap exactly that holds the render
        // to the 72-DPI baseline (sqrt(1) = 1).
        $processor = new PdfPreviewProcessor(new Psr17Factory(), maxSourceBytes: 1_048_576, maxSourcePixels: 20_000);

        $processed = $processor->process(
            $this->stream(Pdf::page(200, 100)),
            'application/pdf',
            new Preset(name: 'x', format: Format::Png, width: 1000, upscale: false),
        );

        Assert::true($processed->width > 190 && $processed->width < 215, "cap boundary did not render: {$processed->width}");
    }

    /** A zero cap means no cap — the full 150 DPI, not a refusal. */
    public function aZeroPixelCapRendersAtTheFullDpi(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }

        $processor = new PdfPreviewProcessor(new Psr17Factory(), maxSourceBytes: 1_048_576, maxSourcePixels: 0);

        $processed = $processor->process(
            $this->stream(Pdf::page(200, 100)),
            'application/pdf',
            new Preset(name: 'x', format: Format::Png, width: 1000, upscale: false),
        );

        Assert::true($processed->width > 400, "zero cap did not render at the full DPI: {$processed->width}");
    }

    /** A page of zero points is not a renderable page. */
    public function anEmptyPageIsRefused(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }

        Expect::exception(ProcessingFailedException::class);

        $this->processor()->process(
            $this->stream(Pdf::page(0, 0)),
            'application/pdf',
            new Preset(name: 'x', format: Format::Webp, width: 100),
        );
    }

    /**
     * The render DPI is chosen from the page geometry and the pixel cap: a
     * tight cap renders a small page at ~72 DPI (not the 150 default), which
     * an upscale-refusing preset exposes as the output's own size.
     */
    public function theRenderDpiFollowsThePixelCap(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }

        // Page 200x100 pt = 20,000 px at the 72-DPI baseline; the cap just
        // above it holds the render to ~72-90 DPI, so the raster is roughly
        // the page's own point size.
        $processor = new PdfPreviewProcessor(new Psr17Factory(), maxSourceBytes: 1_048_576, maxSourcePixels: 25_000);

        $processed = $processor->process(
            $this->stream(Pdf::page(200, 100)),
            'application/pdf',
            new Preset(name: 'dpi', format: Format::Png, width: 1000, upscale: false),
        );

        // 72 * sqrt(25000/20000) = 80 DPI -> raster ~222x111; contain into a
        // 1000-wide box refuses to upscale, so the raster IS the output.
        Assert::true($processed->width > 210 && $processed->width < 240, "render DPI did not follow the cap: {$processed->width}");
        Assert::true($processed->height > 105 && $processed->height < 120, "render DPI did not follow the cap: {$processed->height}");
    }

    /** Garbage that carries the PDF media type fails as a processing error, not a crash. */
    public function garbageFailsAsAProcessingError(): void
    {
        if (!$this->available()) {
            throw new SkipTest('the imagick PDF delegate is not available');
        }
        if (getenv('INFECTION')) {
            // A ghostscript fatal abort sends SIGTERM to its process group,
            // which under Infection's initial run takes the whole Testo
            // suite down with exit 143. The guard these tests prove runs in
            // every other context; Infection's initial pass skips the crash.
            throw new SkipTest('a crashing gs aborts the Infection process group');
        }


        Expect::exception(ProcessingFailedException::class);

        $this->processor()->process(
            $this->stream('%PDF-1.4 and then nothing parseable'),
            'application/pdf',
            new Preset(name: 'x', format: Format::Webp, width: 100),
        );
    }

    private function processor(): PdfPreviewProcessor
    {
        return new PdfPreviewProcessor(new Psr17Factory());
    }

    private static ?bool $available = null;

    /**
     * A functional probe, not a registry check: a runner can carry imagick
     * with the PDF delegate listed while the ghostscript binary itself is
     * absent — `queryFormats('PDF')` is true there and every render dies on
     * `gs: not found`. Ping a real one-page PDF once and let the delegate
     * prove itself.
     */
    private function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        if (!\extension_loaded('imagick') || \Imagick::queryFormats('PDF') === []) {
            return self::$available = false;
        }

        try {
            $ping = new \Imagick();
            $ping->pingImageBlob(Pdf::page());
            $ping->clear();

            return self::$available = true;
        } catch (\Throwable) {
            return self::$available = false;
        }
    }

    private function stream(string $bytes): \Psr\Http\Message\StreamInterface
    {
        return (new Psr17Factory())->createStream($bytes);
    }
}
