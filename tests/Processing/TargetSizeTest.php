<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Processing;

use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Yii3FilestorageImages\Fit;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\Processing\TargetSize;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(TargetSize::class)]
final class TargetSizeTest
{
    public function containScalesDownPreservingAspect(): void
    {
        $target = TargetSize::calculate(
            sourceWidth: 400,
            sourceHeight: 200,
            preset: new Preset(name: 'thumb', format: Format::Webp, width: 100, height: 100),
        );

        Assert::same($target->width, 100);
        Assert::same($target->height, 50);
        Assert::same([$target->sourceX, $target->sourceY], [0, 0]);
        Assert::same([$target->sourceWidth, $target->sourceHeight], [400, 200]);
    }

    public function containWithOneSideConstrainsOnlyThatSide(): void
    {
        $target = TargetSize::calculate(
            sourceWidth: 400,
            sourceHeight: 200,
            preset: new Preset(name: 'wide', format: Format::Webp, height: 100),
        );

        Assert::same($target->width, 200);
        Assert::same($target->height, 100);
    }

    public function containNeverUpscalesByDefault(): void
    {
        $target = TargetSize::calculate(
            sourceWidth: 50,
            sourceHeight: 30,
            preset: new Preset(name: 'thumb', format: Format::Webp, width: 200, height: 200),
        );

        Assert::same([$target->width, $target->height], [50, 30]);
    }

    public function containUpscalesWhenAsked(): void
    {
        $target = TargetSize::calculate(
            sourceWidth: 50,
            sourceHeight: 30,
            preset: new Preset(name: 'big', format: Format::Webp, width: 100, height: 100, upscale: true),
        );

        Assert::same([$target->width, $target->height], [100, 60]);
    }

    public function coverFillsTheBoxAndCropsTheLongSideCentered(): void
    {
        $target = TargetSize::calculate(
            sourceWidth: 400,
            sourceHeight: 200,
            preset: new Preset(name: 'sq', format: Format::Webp, width: 100, height: 100, fit: Fit::Cover),
        );

        Assert::same([$target->width, $target->height], [100, 100]);
        Assert::same([$target->sourceWidth, $target->sourceHeight], [200, 200]);
        Assert::same([$target->sourceX, $target->sourceY], [100, 0]);
    }

    public function coverWithoutUpscaleCropsToTheBoxAspectAtSourceScale(): void
    {
        $target = TargetSize::calculate(
            sourceWidth: 80,
            sourceHeight: 40,
            preset: new Preset(name: 'sq', format: Format::Webp, width: 100, height: 100, fit: Fit::Cover),
        );

        // The box cannot be filled without enlarging, so the output is the
        // largest square window the source has.
        Assert::same([$target->width, $target->height], [40, 40]);
        Assert::same([$target->sourceWidth, $target->sourceHeight], [40, 40]);
        Assert::same([$target->sourceX, $target->sourceY], [20, 0]);
    }

    /**
     * The crop window's height comes from the box ratio applied to the source
     * width — when the source is taller than the box aspect, that formula and
     * not the source height has to be the answer.
     */
    public function coverTakesTheWindowHeightFromTheBoxRatio(): void
    {
        $target = TargetSize::calculate(
            sourceWidth: 100,
            sourceHeight: 300,
            preset: new Preset(name: 'sq', format: Format::Webp, width: 100, height: 100, fit: Fit::Cover),
        );

        Assert::same([$target->sourceWidth, $target->sourceHeight], [100, 100]);
        Assert::same([$target->sourceX, $target->sourceY], [0, 100]);
    }

    /**
     * An odd source-minus-window difference centers with floor division: the
     * leftover pixel goes to the far side, on both axes independently.
     */
    public function coverCentersOddDifferencesWithFloorDivision(): void
    {
        $wide = TargetSize::calculate(
            sourceWidth: 405,
            sourceHeight: 200,
            preset: new Preset(name: 'sq', format: Format::Webp, width: 100, height: 100, fit: Fit::Cover),
        );
        $tall = TargetSize::calculate(
            sourceWidth: 200,
            sourceHeight: 405,
            preset: new Preset(name: 'sq', format: Format::Webp, width: 100, height: 100, fit: Fit::Cover),
        );

        // Window is 200x200 for both; 405 - 200 = 205, floor(205 / 2) = 102.
        Assert::same($wide->sourceX, 102);
        Assert::same($tall->sourceY, 102);
    }

    /**
     * Rounding is half away from zero, and never down to nothing: a 1×10000
     * source contain-ed into a 5px-tall box is still a 1px-wide image.
     */
    public function containNeverCollapsesASideToZero(): void
    {
        $target = TargetSize::calculate(
            sourceWidth: 1,
            sourceHeight: 10_000,
            preset: new Preset(name: 'strip', format: Format::Webp, height: 5),
        );

        Assert::same([$target->width, $target->height], [1, 5]);
    }

    public function coverRoundsWindowDimensionsHalfAwayFromZero(): void
    {
        // 3 * 1 / 2 = 1.5: round() gives 2, floor() would give 1.
        $target = TargetSize::calculate(
            sourceWidth: 3,
            sourceHeight: 3,
            preset: new Preset(name: 'half', format: Format::Webp, width: 1, height: 2, fit: Fit::Cover),
        );

        Assert::same([$target->sourceWidth, $target->sourceHeight], [2, 3]);
        Assert::same([$target->width, $target->height], [1, 2]);
        Assert::same([$target->sourceX, $target->sourceY], [0, 0]);
    }

    /**
     * @param int<1, 4000> $sourceWidth
     * @param int<1, 4000> $sourceHeight
     * @param int<1, 500> $boxWidth
     * @param int<1, 500> $boxHeight
     */
    #[Property(runs: 400, auto: true)]
    public function containOutputFitsInsideTheBoxAndTheSource(
        int $sourceWidth,
        int $sourceHeight,
        int $boxWidth,
        int $boxHeight,
        bool $upscale,
    ): void {
        $target = TargetSize::calculate($sourceWidth, $sourceHeight, new Preset(
            name: 'p',
            format: Format::Webp,
            width: $boxWidth,
            height: $boxHeight,
            upscale: $upscale,
        ));

        Classify::cover($upscale, 'upscale allowed', 20.0);
        // Labeled but not gated: with sources up to 4000 and boxes up to 500
        // the case is rare by construction, and the Examples pin it anyway.
        Classify::when(
            $sourceWidth <= $boxWidth && $sourceHeight <= $boxHeight,
            'source already inside the box',
        );

        Assert::true($target->width <= $boxWidth);
        Assert::true($target->height <= $boxHeight);
        if (!$upscale) {
            Assert::true($target->width <= $sourceWidth);
            Assert::true($target->height <= $sourceHeight);
        }
        // Contain never crops.
        Assert::same([$target->sourceX, $target->sourceY], [0, 0]);
        Assert::same([$target->sourceWidth, $target->sourceHeight], [$sourceWidth, $sourceHeight]);
    }

    /**
     * Aspect is preserved within the pixel that rounding may move. The
     * comparison is cross-multiplied so no division order is privileged.
     *
     * @param int<1, 4000> $sourceWidth
     * @param int<1, 4000> $sourceHeight
     * @param int<1, 500> $boxWidth
     * @param int<1, 500> $boxHeight
     */
    #[Property(runs: 400, auto: true)]
    public function containPreservesAspectWithinRounding(
        int $sourceWidth,
        int $sourceHeight,
        int $boxWidth,
        int $boxHeight,
    ): void {
        $target = TargetSize::calculate($sourceWidth, $sourceHeight, new Preset(
            name: 'p',
            format: Format::Webp,
            width: $boxWidth,
            height: $boxHeight,
            upscale: true,
        ));

        // width/height ≈ sourceWidth/sourceHeight, tolerance of one output
        // pixel on either side of the cross-product.
        $difference = abs($target->width * $sourceHeight - $target->height * $sourceWidth);

        Assert::true($difference <= max($sourceWidth, $sourceHeight));
    }

    /**
     * @param int<1, 4000> $sourceWidth
     * @param int<1, 4000> $sourceHeight
     * @param int<1, 500> $boxWidth
     * @param int<1, 500> $boxHeight
     */
    #[Property(runs: 400, auto: true)]
    public function coverCropWindowStaysInsideTheSource(
        int $sourceWidth,
        int $sourceHeight,
        int $boxWidth,
        int $boxHeight,
        bool $upscale,
    ): void {
        $target = TargetSize::calculate($sourceWidth, $sourceHeight, new Preset(
            name: 'p',
            format: Format::Webp,
            width: $boxWidth,
            height: $boxHeight,
            fit: Fit::Cover,
            upscale: $upscale,
        ));

        Classify::cover($upscale, 'upscale allowed', 20.0);

        Assert::true($target->sourceX >= 0);
        Assert::true($target->sourceY >= 0);
        Assert::true($target->sourceX + $target->sourceWidth <= $sourceWidth);
        Assert::true($target->sourceY + $target->sourceHeight <= $sourceHeight);
        Assert::true($target->width <= $boxWidth);
        Assert::true($target->height <= $boxHeight);
    }

    /**
     * When enlarging is allowed — or the source already covers the box — cover
     * has no excuse: the output is the box, exactly.
     *
     * @param int<1, 4000> $sourceWidth
     * @param int<1, 4000> $sourceHeight
     * @param int<1, 500> $boxWidth
     * @param int<1, 500> $boxHeight
     */
    #[Property(runs: 400, auto: true)]
    public function coverFillsTheBoxExactlyWhenItMay(
        int $sourceWidth,
        int $sourceHeight,
        int $boxWidth,
        int $boxHeight,
    ): void {
        $target = TargetSize::calculate($sourceWidth, $sourceHeight, new Preset(
            name: 'p',
            format: Format::Webp,
            width: $boxWidth,
            height: $boxHeight,
            fit: Fit::Cover,
            upscale: true,
        ));

        Assert::same([$target->width, $target->height], [$boxWidth, $boxHeight]);
    }

    public static function containOutputFitsInsideTheBoxAndTheSourceExamples(): iterable
    {
        yield '1x1 source' => [1, 1, 100, 100, false];
        yield 'source equals box' => [200, 200, 200, 200, false];
        yield 'extreme aspect' => [1, 4000, 100, 100, false];
        yield 'one off the box' => [101, 101, 100, 100, false];
    }

    public static function coverCropWindowStaysInsideTheSourceExamples(): iterable
    {
        yield '1x1 source' => [1, 1, 100, 100, false];
        yield 'extreme aspect upscaled' => [4000, 1, 100, 100, true];
        yield 'source equals box' => [100, 100, 100, 100, false];
    }
}
