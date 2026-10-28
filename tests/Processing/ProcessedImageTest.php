<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\Yii3FilestorageImages\Processing\ProcessedImage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProcessedImage::class)]
final class ProcessedImageTest
{
    public function carriesStreamAndDimensions(): void
    {
        $stream = (new Psr17Factory())->createStream('bytes');
        $image = new ProcessedImage($stream, width: 10, height: 5);

        Assert::same($image->stream, $stream);
        Assert::same([$image->width, $image->height], [10, 5]);
    }

    /**
     * One pixel is a dimension, not a degenerate case: contain into a
     * near-zero scale produces 1×N outputs, so the floor at one has to accept
     * exactly the boundary it enforces.
     */
    public function acceptsSinglePixelDimensions(): void
    {
        $stream = (new Psr17Factory())->createStream('bytes');

        Assert::same((new ProcessedImage($stream, width: 1, height: 5))->width, 1);
        Assert::same((new ProcessedImage($stream, width: 5, height: 1))->height, 1);
    }

    public function rejectsNonPositiveDimensions(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('must be positive');

        new ProcessedImage((new Psr17Factory())->createStream(''), width: 0, height: 5);
    }
}
