<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Exception;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;

/**
 * A preset definition in `params` is malformed.
 *
 * @api
 */
final class InvalidPresetException extends InvalidArgumentException implements FilestorageException {}
