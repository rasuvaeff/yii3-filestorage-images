<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Processing;

use Rasuvaeff\Yii3FilestorageImages\Fit;
use Rasuvaeff\Yii3FilestorageImages\Preset;

/**
 * The resize geometry, computed once and shared by every processor.
 *
 * GD and Imagick disagree about almost everything except arithmetic, so the
 * arithmetic lives here: the same preset applied to the same source dimensions
 * produces the same output dimensions and the same crop window whichever
 * extension happens to be loaded. This is also the one piece of the pipeline
 * that is a pure function, which is where the property tests go.
 *
 * @api
 */
final readonly class TargetSize
{
    /**
     * @param int<1, max> $width Output canvas width.
     * @param int<1, max> $height Output canvas height.
     * @param int<0, max> $sourceX Crop window origin in source pixels.
     * @param int<0, max> $sourceY
     * @param int<1, max> $sourceWidth Crop window size in source pixels.
     * @param int<1, max> $sourceHeight
     */
    private function __construct(
        public int $width,
        public int $height,
        public int $sourceX,
        public int $sourceY,
        public int $sourceWidth,
        public int $sourceHeight,
    ) {}

    /**
     * @param int<1, max> $sourceWidth
     * @param int<1, max> $sourceHeight
     */
    public static function calculate(int $sourceWidth, int $sourceHeight, Preset $preset): self
    {
        return $preset->fit === Fit::Cover
            ? self::cover($sourceWidth, $sourceHeight, $preset)
            : self::contain($sourceWidth, $sourceHeight, $preset);
    }

    /**
     * @param int<1, max> $sourceWidth
     * @param int<1, max> $sourceHeight
     */
    private static function contain(int $sourceWidth, int $sourceHeight, Preset $preset): self
    {
        // A missing side means "unconstrained", which for a minimum is the
        // same as "never the limiting factor".
        $scale = min(
            $preset->width === null ? INF : (float) $preset->width / (float) $sourceWidth,
            $preset->height === null ? INF : (float) $preset->height / (float) $sourceHeight,
        );
        if (!$preset->upscale) {
            $scale = min($scale, 1.0);
        }

        return new self(
            width: self::dimension((float) $sourceWidth * $scale),
            height: self::dimension((float) $sourceHeight * $scale),
            sourceX: 0,
            sourceY: 0,
            sourceWidth: $sourceWidth,
            sourceHeight: $sourceHeight,
        );
    }

    /**
     * @param int<1, max> $sourceWidth
     * @param int<1, max> $sourceHeight
     */
    private static function cover(int $sourceWidth, int $sourceHeight, Preset $preset): self
    {
        // Preset validation guarantees both sides for Fit::Cover.
        \assert($preset->width !== null && $preset->height !== null);

        // The crop window is the largest box-proportioned rectangle the
        // source has, centered — whichever source side is relatively longer
        // than the box gets trimmed.
        $cropWidth = min(
            $sourceWidth,
            self::dimension((float) $sourceHeight * (float) $preset->width / (float) $preset->height),
        );
        $cropHeight = min(
            $sourceHeight,
            self::dimension((float) $sourceWidth * (float) $preset->height / (float) $preset->width),
        );

        // Filling the box means enlarging whenever the window is smaller than
        // the box. Refusing to enlarge means the output *is* the window: a
        // box-proportioned image at the source's own scale. Blowing pixels up
        // to keep a promise about canvas size would be the worse lie.
        $mayFill = $preset->upscale || ($cropWidth >= $preset->width && $cropHeight >= $preset->height);

        return new self(
            width: $mayFill ? $preset->width : min($preset->width, $cropWidth),
            height: $mayFill ? $preset->height : min($preset->height, $cropHeight),
            sourceX: max(0, intdiv($sourceWidth - $cropWidth, 2)),
            sourceY: max(0, intdiv($sourceHeight - $cropHeight, 2)),
            sourceWidth: $cropWidth,
            sourceHeight: $cropHeight,
        );
    }

    /**
     * @return int<1, max> Rounded, and never rounded down to nothing: a
     *         1×10000 source scaled into a small box still has to be an image.
     */
    private static function dimension(float $value): int
    {
        return max(1, (int) round($value));
    }
}
