<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImagickFailure;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Test;

#[Test]
#[Covers(ImagickFailure::class)]
final class ImagickFailureTest
{
    /** ImageMagick's PolicyError: the installation forbids the coder. */
    public function aPolicyErrorIsAnUnsupportedSource(): void
    {
        $this->requireExtension();

        $previous = new \ImagickException('not authorized by the security policy `PDF\'', 499);
        $mapped = ImagickFailure::from($previous, 'context');

        Assert::instanceOf($mapped, UnsupportedSourceException::class);
        Assert::same($mapped->getPrevious(), $previous);
        Assert::string($mapped->getMessage())->contains('security policy forbids this source');
    }

    /** Every other error is a file that did not decode, with its context. */
    public function anyOtherErrorIsAProcessingFailure(): void
    {
        $this->requireExtension();

        $previous = new \ImagickException('improper image header', 450);
        $mapped = ImagickFailure::from($previous, 'Imagick could not process the image');

        Assert::instanceOf($mapped, ProcessingFailedException::class);
        Assert::same($mapped->getPrevious(), $previous);
        Assert::same($mapped->getMessage(), 'Imagick could not process the image: improper image header');
    }

    private function requireExtension(): void
    {
        if (!\extension_loaded('imagick')) {
            throw new SkipTest('ext-imagick is not loaded');
        }
    }
}
