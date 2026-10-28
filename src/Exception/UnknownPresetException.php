<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Exception;

use Rasuvaeff\Yii3Filestorage\Exception\UnresolvableVariantException;

/**
 * A preset name that is not in the configured whitelist.
 *
 * Deliberately a distinct type: a signed URL carrying an unknown variant must
 * become a 404, and the caller can only map "unknown preset" — as opposed to
 * "known preset whose generation failed" — when the two are different classes.
 * It is core's unresolvable-variant fact, narrowed to this package's presets.
 *
 * @api
 */
final class UnknownPresetException extends UnresolvableVariantException {}
