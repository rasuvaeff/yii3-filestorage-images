<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use ImagickException;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;

/**
 * Maps an ImageMagick error to the package's verdict. A `policy.xml` refusal
 * (ImageMagick's `PolicyError`, code 499) says the installation will never
 * process this coder — an unsupported source, which `filestorage:warm` counts
 * as a skip; everything else is a file that did not decode.
 *
 * @internal
 */
final readonly class ImagickFailure
{
    private const int POLICY_ERROR = 499;

    public static function from(
        ImagickException $exception,
        string $context,
    ): ProcessingFailedException|UnsupportedSourceException {
        if ($exception->getCode() === self::POLICY_ERROR) {
            return new UnsupportedSourceException(
                "ImageMagick's security policy forbids this source: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        return new ProcessingFailedException("{$context}: {$exception->getMessage()}", previous: $exception);
    }
}
