<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Exception;

use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use RuntimeException;

/**
 * The bytes claimed to be an image and were not, or the decoder/encoder
 * failed on them. Unlike {@see UnsupportedSourceException} this is a property
 * of the specific file — a truncated JPEG, a PNG whose header lies — so
 * retrying with another processor is pointless.
 *
 * @api
 */
final class ProcessingFailedException extends RuntimeException implements FilestorageException {}
