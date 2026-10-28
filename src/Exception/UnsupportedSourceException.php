<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Exception;

use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use RuntimeException;

/**
 * The stored file cannot be turned into this derivative — not an error in the
 * file and not a bug in the pipeline, just a combination this installation
 * does not process: a PDF where only raster images are supported, a source
 * over the byte or pixel guard, an output format no loaded extension encodes.
 *
 * `filestorage:warm` counts these as skips, not failures.
 *
 * @api
 */
final class UnsupportedSourceException extends RuntimeException implements FilestorageException {}
