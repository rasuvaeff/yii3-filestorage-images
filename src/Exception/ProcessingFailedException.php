<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Exception;

use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use RuntimeException;
use Throwable;

/**
 * The bytes claimed to be an image and were not, or the decoder/encoder
 * failed on them. Unlike {@see UnsupportedSourceException} this is a property
 * of the specific file — a truncated JPEG, a PNG whose header lies — so
 * retrying with another processor is pointless.
 *
 * A *transient* failure ({@see self::transient()}) says nothing about the
 * file: the source stream stalled, the temporary file could not be written,
 * ImageMagick ran out of a resource under load. `Derivatives` never remembers
 * one in its failure cache — the next request tries again.
 *
 * @api
 */
final class ProcessingFailedException extends RuntimeException implements FilestorageException
{
    private bool $transient = false;

    public static function transient(string $message, ?Throwable $previous = null): self
    {
        $exception = new self($message, 0, $previous);
        $exception->transient = true;

        return $exception;
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}
