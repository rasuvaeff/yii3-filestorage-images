<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Svg;

use Rasuvaeff\Yii3FilestorageImages\Exception\SvgSanitizationException;

/**
 * Makes an SVG safe to store and — with the right delivery policy — to serve.
 *
 * `finfo` correctly identifying `image/svg+xml` says nothing about whether
 * serving the file inline is safe: an SVG is a full XML document that can
 * carry scripts, remote references and animation, and one served from your own
 * origin is a stored-XSS primitive. Sanitisation is the opt-in that makes an
 * `image/svg+xml` group defensible at all; without it the storage family's
 * default is the honest one — attachment + `nosniff`, never inline.
 *
 * @api
 */
interface SvgSanitizerInterface
{
    /**
     * @param non-empty-string $svg The document as uploaded.
     *
     * @return non-empty-string The sanitized document. Never a best effort:
     *         input that cannot be made safe throws.
     *
     * @throws SvgSanitizationException
     */
    public function sanitize(string $svg): string;
}
