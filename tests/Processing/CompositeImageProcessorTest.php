<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\CompositeImageProcessor;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Doubles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;

#[Test]
#[Covers(CompositeImageProcessor::class)]
final class CompositeImageProcessorTest
{
    public function routesToTheFirstSupportingProcessor(): void
    {
        $refusing = Doubles::processor(supports: false);
        $accepting = Doubles::processor();
        $composite = new CompositeImageProcessor([$refusing, $accepting]);
        $preset = new Preset(name: 'thumb', format: Format::Webp, width: 10);

        Assert::true($composite->supports('image/png', Format::Webp));

        $composite->process((new Psr17Factory())->createStream('bytes'), 'image/png', $preset);

        // The composite consults the first processor twice: once for the
        // explicit supports() probe and once more to route process().
        verify(fn() => $refusing->supports('image/png', Format::Webp), times: 2);
        verify(fn() => $refusing->process(Arg::any(), Arg::any(), Arg::any()), never: true);
        verify(fn() => $accepting->process(Arg::any(), 'image/png', $preset), times: 1);
    }

    public function supportsNothingWhenEveryProcessorRefuses(): void
    {
        $composite = new CompositeImageProcessor([Doubles::processor(supports: false)]);

        Assert::false($composite->supports('image/png', Format::Webp));
    }

    public function namesTheImpossiblePairWhenProcessorsExist(): void
    {
        $composite = new CompositeImageProcessor([Doubles::processor(supports: false)]);

        Expect::exception(UnsupportedSourceException::class)
            ->withMessageContaining('"image/png" into "webp"');

        $composite->process(
            (new Psr17Factory())->createStream('bytes'),
            'image/png',
            new Preset(name: 'thumb', format: Format::Webp, width: 10),
        );
    }

    public function namesTheMissingExtensionsWhenEmpty(): void
    {
        Expect::exception(UnsupportedSourceException::class)
            ->withMessageContaining('load ext-imagick or ext-gd');

        (new CompositeImageProcessor([]))->process(
            (new Psr17Factory())->createStream('bytes'),
            'image/png',
            new Preset(name: 'thumb', format: Format::Webp, width: 10),
        );
    }

    public function fromAvailableMirrorsTheLoadedExtensions(): void
    {
        $composite = CompositeImageProcessor::fromAvailable(new Psr17Factory());

        // The build has to be honest on any runtime, loaded extensions or not:
        // with neither extension the composite supports nothing, with either
        // one PNG→WebP — every distribution of both builds them in.
        Assert::same(
            $composite->supports('image/png', Format::Webp),
            \extension_loaded('imagick') || \extension_loaded('gd'),
        );
    }

    /**
     * `fromAvailable()` lists exactly the processors whose extension is
     * loaded, Imagick first. The composition is the routing table: anything
     * else in that array would answer `supports()` and `process()` calls no
     * installed extension can back.
     */
    public function fromAvailableListsExactlyTheLoadedProcessors(): void
    {
        $expected = [];
        if (\extension_loaded('imagick')) {
            $expected[] = \Rasuvaeff\Yii3FilestorageImages\Processing\ImagickImageProcessor::class;
        }
        if (\extension_loaded('gd')) {
            $expected[] = \Rasuvaeff\Yii3FilestorageImages\Processing\GdImageProcessor::class;
        }
        // The PDF preview rides along on imagick only: it answers nothing
        // for raster sources, so the raster order above is undisturbed — and
        // a runtime with neither extension keeps the empty list.
        if (\extension_loaded('imagick')) {
            $expected[] = \Rasuvaeff\Yii3FilestorageImages\Processing\PdfPreviewProcessor::class;
        }

        $composite = CompositeImageProcessor::fromAvailable(new Psr17Factory());
        $processors = (new \ReflectionProperty(CompositeImageProcessor::class, 'processors'))->getValue($composite);

        Assert::same(
            \array_map(\get_class(...), \is_array($processors) ? $processors : []),
            $expected,
        );
    }
}
