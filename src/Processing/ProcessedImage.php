<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * An encoded derivative, ready to be written to a store.
 *
 * @api
 */
final readonly class ProcessedImage
{
    /** @var int<1, max> */
    public int $width;

    /** @var int<1, max> */
    public int $height;

    /**
     * @param StreamInterface $stream Seekable and rewound; the store reads it
     *        from the start.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public StreamInterface $stream,
        int $width,
        int $height,
    ) {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Processed image dimensions must be positive');
        }

        $this->width = $width;
        $this->height = $height;
    }
}
