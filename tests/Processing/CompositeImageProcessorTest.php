<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\CompositeImageProcessor;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\RecordingProcessor;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(CompositeImageProcessor::class)]
final class CompositeImageProcessorTest
{
    public function routesToTheFirstSupportingProcessor(): void
    {
        $refusing = new RecordingProcessor(supportsEverything: false);
        $accepting = new RecordingProcessor();
        $composite = new CompositeImageProcessor([$refusing, $accepting]);
        $preset = new Preset(name: 'thumb', format: Format::Webp, width: 10);

        Assert::true($composite->supports('image/png', Format::Webp));

        $composite->process((new Psr17Factory())->createStream('bytes'), 'image/png', $preset);

        Assert::same($refusing->calls, []);
        Assert::same($accepting->calls, [['image/png', 'thumb']]);
    }

    public function supportsNothingWhenEveryProcessorRefuses(): void
    {
        $composite = new CompositeImageProcessor([new RecordingProcessor(supportsEverything: false)]);

        Assert::false($composite->supports('image/png', Format::Webp));
    }

    public function namesTheImpossiblePairWhenProcessorsExist(): void
    {
        $composite = new CompositeImageProcessor([new RecordingProcessor(supportsEverything: false)]);

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
}
