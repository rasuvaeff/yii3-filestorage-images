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
 * as a skip; a corrupt image or a coder error is a file that did not decode;
 * everything else is a transient processing failure.
 *
 * @internal
 */
final readonly class ImagickFailure
{
    private const int POLICY_ERROR = 499;

    /**
     * ImageMagick severities that are a verdict on the bytes: CorruptImage
     * and Coder, as warning (3xx) and error (4xx), and DelegateError (415) —
     * ghostscript refusing a malformed PDF, measured. The render is bounded
     * by `pdf:fit-page`, so a delegate failing for want of resources is rare,
     * and not remembering it would re-run ghostscript on every request for a
     * broken PDF — the amplifier the failure cache exists to stop.
     * Everything else — resource limits, the pixel cache, a file that could
     * not be opened — can pass by itself and is transient (review 1.0 pass 2,
     * N8).
     */
    private const array ABOUT_THE_FILE = [325, 350, 415, 425, 450];

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

        $message = "{$context}: {$exception->getMessage()}";

        return \in_array($exception->getCode(), self::ABOUT_THE_FILE, strict: true)
            ? new ProcessingFailedException($message, previous: $exception)
            : ProcessingFailedException::transient($message, $exception);
    }
}
