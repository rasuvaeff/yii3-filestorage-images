<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Exception;

use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use RuntimeException;

/**
 * Generation could not run: the file's store cannot hold derivatives, the
 * original's bytes are gone, or the generation lock could not be acquired and
 * nobody else produced the object either.
 *
 * @api
 */
final class DerivativeGenerationException extends RuntimeException implements FilestorageException {}
