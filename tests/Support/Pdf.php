<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

/**
 * Minimal blank-page PDFs, built as bytes.
 *
 * The page geometry — the only thing the tests assert — lives in the
 * MediaBox; the content streams are a single space, because a blank page is
 * the one thing every ghostscript version renders identically and a drawn
 * rectangle is not (Ubuntu's gs refuses the hand-made drawing operators
 * Alpine's accepts). The streams follow the letter of the spec — `/Length`
 * counts exactly the payload bytes, and `endstream` is preceded by an EOL —
 * which is what makes the fixtures portable across gs builds.
 *
 * @internal
 */
final class Pdf
{
    /**
     * @param array<int, string> $objects
     *
     * @return non-empty-string
     */
    private static function build(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $object . "\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (\count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size ' . (\count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    /**
     * @param non-empty-string $content The exact payload bytes; /Length counts them.
     *
     * @return non-empty-string The `stream … endstream` object body.
     */
    private static function stream(string $content): string
    {
        return '<< /Length ' . strlen($content) . " >> stream\n" . $content . "\nendstream";
    }

    /**
     * @return non-empty-string A valid one-page PDF of the given point size.
     */
    public static function page(int $width = 200, int $height = 100): string
    {
        return self::build([
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}]"
                . ' /Resources << /ProcSet [/PDF] >> /Contents 4 0 R >> endobj',
            '4 0 obj ' . self::stream(' ') . ' endobj',
        ]);
    }

    /**
     * @return non-empty-string A real two-page PDF: the first page of the
     *         given size, the second one (by default much smaller) — the
     *         preview must come from the first page alone.
     */
    public static function twoPages(int $width, int $height, int $secondWidth = 20, int $secondHeight = 10): string
    {
        return self::build([
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >> endobj',
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}]"
                . ' /Resources << /ProcSet [/PDF] >> /Contents 4 0 R >> endobj',
            '4 0 obj ' . self::stream(' ') . ' endobj',
            "5 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$secondWidth} {$secondHeight}]"
                . ' /Resources << /ProcSet [/PDF] >> /Contents 6 0 R >> endobj',
            '6 0 obj ' . self::stream(' ') . ' endobj',
        ]);
    }

    /**
     * A page whose real size ImageMagick's ping cannot see: the MediaBox is
     * an indirect reference, which the PDF coder's scan of the raw text does
     * not resolve (it reports its letter-size default), while ghostscript
     * renders the referenced box (review 1.0 pass 2, N1).
     *
     * @return non-empty-string
     */
    public static function indirectMediaBox(int $width, int $height): string
    {
        return self::build([
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox 5 0 R'
                . ' /Resources << /ProcSet [/PDF] >> /Contents 4 0 R >> endobj',
            '4 0 obj ' . self::stream(' ') . ' endobj',
            "5 0 obj [0 0 {$width} {$height}] endobj",
        ]);
    }

    /**
     * A page whose `/UserUnit` multiplies its box: ImageMagick's ping ignores
     * the unit, ghostscript honours it (review 1.0 pass 2, N1).
     *
     * @return non-empty-string
     */
    public static function userUnit(int $width, int $height, int $unit): string
    {
        return self::build([
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] /UserUnit {$unit}"
                . ' /Resources << /ProcSet [/PDF] >> /Contents 4 0 R >> endobj',
            '4 0 obj ' . self::stream(' ') . ' endobj',
        ]);
    }
}
