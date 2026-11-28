<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImagickFailure;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Data\DataProvider;
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

    /**
     * Review 1.0 pass 2, N8: only errors about the bytes — a corrupt image, a
     * coder that cannot read them — are a verdict on the file. Resource
     * limits, cache, delegate, file-open and every other error are
     * transient: the next request tries again.
     */
    #[DataProvider('severityProvider')]
    public function onlyCorruptAndCoderErrorsAreAboutTheFile(int $code, bool $transient): void
    {
        $this->requireExtension();

        $mapped = ImagickFailure::from(new \ImagickException('x', $code), 'context');

        Assert::instanceOf($mapped, ProcessingFailedException::class);
        Assert::same($mapped->isTransient(), $transient);
    }

    public static function severityProvider(): iterable
    {
        yield 'CorruptImageWarning' => [325, false];
        yield 'CoderWarning' => [350, false];
        yield 'CorruptImageError' => [425, false];
        yield 'CoderError' => [450, false];
        yield 'ResourceLimitError' => [400, true];
        yield 'ResourceLimitWarning' => [300, true];
        yield 'DelegateError' => [415, true];
        yield 'CacheError' => [445, true];
        yield 'FileOpenError' => [430, true];
        yield 'fatal ResourceLimit' => [700, true];
        yield 'no code' => [0, true];
    }

    private function requireExtension(): void
    {
        if (!\extension_loaded('imagick')) {
            throw new SkipTest('ext-imagick is not loaded');
        }
    }
}
