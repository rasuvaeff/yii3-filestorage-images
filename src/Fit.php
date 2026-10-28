<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages;

/**
 * How a source image maps onto a preset's box.
 *
 * @api
 */
enum Fit: string
{
    /**
     * Scale to fit inside the box, preserving aspect ratio. The output is at
     * most the box; one dimension may be smaller. With a single box dimension
     * the other is unconstrained.
     */
    case Contain = 'contain';

    /**
     * Fill the box exactly, preserving aspect ratio by cropping the source
     * around its center. Requires both box dimensions — "cover a box with one
     * side" has no answer.
     */
    case Cover = 'cover';
}
