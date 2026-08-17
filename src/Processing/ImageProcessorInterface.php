<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3FilestorageImages\Exception\ProcessingFailedException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;

/**
 * Decodes a source image and encodes one preset's rendition of it.
 *
 * Transformation genuinely needs the whole image decoded, so implementations
 * buffer — the streaming guarantee the storage family makes is about storing
 * and serving, never about transforming. What bounds a transform instead is
 * the pair of guards every implementation enforces before decoding: a byte cap
 * on the source and a pixel cap read from the image header, because a 200-byte
 * PNG is allowed to claim to be 100000×100000 and the allocation happens at
 * decode time, not at read time.
 *
 * @api
 */
interface ImageProcessorInterface
{
    /**
     * Whether this processor can decode the source type and encode the output
     * format *right now* — availability is a property of the runtime (which
     * extensions are loaded, what they were compiled with), so the answer is
     * asked, never assumed.
     *
     * @param non-empty-string $sourceMediaType Authoritative detected type,
     *        never the client hint.
     */
    public function supports(string $sourceMediaType, Format $output): bool;

    /**
     * @param non-empty-string $sourceMediaType
     *
     * @throws UnsupportedSourceException When {@see supports()} is false or a
     *         source guard rejects the input.
     * @throws ProcessingFailedException When the bytes do not decode or the
     *         encoder fails.
     */
    public function process(StreamInterface $source, string $sourceMediaType, Preset $preset): ProcessedImage;
}
