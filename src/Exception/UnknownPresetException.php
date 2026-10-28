<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Exception;

use InvalidArgumentException;
use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;

/**
 * A preset name that is not in the configured whitelist.
 *
 * Deliberately a distinct type: a signed URL carrying an unknown variant must
 * become a 404, and the caller can only map "unknown preset" — as opposed to
 * "known preset whose generation failed" — when the two are different classes.
 *
 * @api
 */
final class UnknownPresetException extends InvalidArgumentException implements FilestorageException {}
