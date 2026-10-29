<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

/**
 * Minimal one-page PDFs, built as bytes: a letter-size page with a filled
 * rectangle, and an oversized page for the pixel-cap guard.
 *
 * @internal
 */
final class Pdf
{
    /**
     * @return non-empty-string A two-page PDF: the first page of the given
     *         size, a second one smaller — the preview must come from the
     *         first page alone.
     */
    public static function twoPages(int $width, int $height): string
    {
        return str_replace('/Count 1', '/Count 2', self::page($width, $height));
    }

    /**
     * @return non-empty-string A valid one-page PDF of the given point size.
     */
    public static function page(int $width = 200, int $height = 100): string
    {
        $content = "0 0 1 rg 10 10 " . ($width - 20) . ' ' . ($height - 20) . " re f\n";

        $objects = [
            '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj',
            '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj',
            "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 {$width} {$height}] /Resources << /ProcSet [/PDF] >> /Contents 4 0 R >> endobj",
            '4 0 obj << /Length ' . strlen($content) . " >> stream\n" . $content . 'endstream endobj',
        ];

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
}
