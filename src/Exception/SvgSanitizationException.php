<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Exception;

use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use RuntimeException;

/**
 * The SVG could not be made safe.
 *
 * Thrown instead of returning a best effort: an SVG served inline from your
 * own origin is a stored-XSS primitive, so "sanitized, probably" is not an
 * output this contract has.
 *
 * @api
 */
final class SvgSanitizationException extends RuntimeException implements FilestorageException {}
