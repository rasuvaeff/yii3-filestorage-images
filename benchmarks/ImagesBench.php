<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Benchmarks;

use DOMDocument;
use Rasuvaeff\Yii3FilestorageImages\Fit;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Rasuvaeff\Yii3FilestorageImages\Processing\TargetSize;
use Rasuvaeff\Yii3FilestorageImages\Svg\EnshrinedSvgSanitizer;
use Testo\Bench;

/**
 * The CPU-bound steps this package adds around a rendition request. The
 * decode/encode itself belongs to GD or ImageMagick and depends on the loaded
 * extension, so it is deliberately not benchmarked here — the geometry, the
 * preset resolution and the SVG pipeline are ours and run on every runtime.
 * Each subject is compared against the cheapest thing that could possibly do
 * the job, so the cost of a guarantee is visible rather than assumed.
 *
 * @internal
 */
final class ImagesBench
{
    /**
     * Validated crop-window geometry vs the two divisions a hand-rolled
     * resize would do.
     */
    #[Bench(
        callables: ['bare ratio math' => [self::class, 'bareRatioMath']],
        calls: 50_000,
        iterations: 10,
    )]
    public static function geometry(): TargetSize
    {
        return TargetSize::calculate(sourceWidth: 4032, sourceHeight: 3024, preset: self::preset());
    }

    /**
     * @return array{int, int}
     */
    public static function bareRatioMath(): array
    {
        $scale = max(200 / 4032, 200 / 3024);

        return [(int) round(4032 * $scale), (int) round(3024 * $scale)];
    }

    /**
     * Whitelist lookup + validated descriptor vs trusting a concatenated
     * string — the difference is what makes an unknown preset a 404 instead
     * of a filename.
     */
    #[Bench(
        callables: ['string concat' => [self::class, 'concatFileName']],
        calls: 20_000,
        iterations: 10,
    )]
    public static function presetResolution(): string
    {
        return self::registry()->get('thumb')->descriptor()->fileName();
    }

    public static function concatFileName(): string
    {
        return 'thumb' . '-v' . 1 . '.' . 'webp';
    }

    /**
     * The full sanitation pipeline (library pass + post-pass + caps) vs a
     * bare DOM parse/serialize round-trip — the parse both must pay.
     */
    #[Bench(
        callables: ['bare DOM round-trip' => [self::class, 'bareDomRoundTrip']],
        calls: 200,
        iterations: 10,
    )]
    public static function svgSanitization(): string
    {
        return (new EnshrinedSvgSanitizer())->sanitize(self::svg());
    }

    public static function bareDomRoundTrip(): string
    {
        $document = new DOMDocument();
        $document->loadXML(self::svg(), LIBXML_NONET);

        return (string) $document->saveXML($document->documentElement);
    }

    private static function preset(): Preset
    {
        return new Preset(name: 'thumb', format: Format::Webp, width: 200, height: 200, fit: Fit::Cover);
    }

    private static function registry(): PresetRegistry
    {
        return new PresetRegistry([self::preset()]);
    }

    private static function svg(): string
    {
        $shapes = '';
        for ($i = 0; $i < 50; $i++) {
            $shapes .= "<rect x=\"{$i}\" y=\"{$i}\" width=\"10\" height=\"10\" fill=\"#0aa\"/>";
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">' . $shapes . '</svg>';
    }
}
