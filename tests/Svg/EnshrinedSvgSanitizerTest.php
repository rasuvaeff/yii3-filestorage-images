<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Svg;

use Rasuvaeff\Yii3FilestorageImages\Exception\SvgSanitizationException;
use Rasuvaeff\Yii3FilestorageImages\Svg\EnshrinedSvgSanitizer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(EnshrinedSvgSanitizer::class)]
final class EnshrinedSvgSanitizerTest
{
    private EnshrinedSvgSanitizer $sanitizer;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->sanitizer = new EnshrinedSvgSanitizer();
    }

    public function passesAPlainSvgThrough(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>',
        );

        Assert::string($clean)->contains('<rect');
        Assert::string($clean)->contains('<svg');
    }

    public function stripsScripts(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>',
        );

        Assert::false(str_contains($clean, 'script'));
        Assert::false(str_contains($clean, 'alert'));
    }

    public function stripsEventHandlers(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" onload="alert(1)"/></svg>',
        );

        Assert::false(str_contains($clean, 'onload'));
    }

    public function stripsRemoteReferences(): void
    {
        $clean = $this->sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<use xlink:href="https://evil.example/def.svg#x"/><rect width="1" height="1"/></svg>',
        );

        Assert::false(str_contains($clean, 'evil.example'));
    }

    /**
     * The post-pass: elements the library keeps but this contract does not —
     * `foreignObject` embeds arbitrary HTML, `animate`/`set`/`mpath` rewrite
     * attributes (`href` included) after the static scan is over.
     */
    #[DataProvider('forbiddenElementProvider')]
    public function stripsBehaviouralElements(string $element): void
    {
        $clean = $this->sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg">'
            . "<{$element}><div>x</div></{$element}>"
            . '<rect width="1" height="1"/></svg>',
        );

        Assert::false(str_contains($clean, $element));
        Assert::string($clean)->contains('<rect');
    }

    public static function forbiddenElementProvider(): iterable
    {
        yield 'foreignObject' => ['foreignObject'];
        yield 'animate' => ['animate'];
        yield 'animateMotion' => ['animateMotion'];
        yield 'animateTransform' => ['animateTransform'];
        yield 'set' => ['set'];
        yield 'mpath' => ['mpath'];
    }

    public function rejectsADocumentOverTheByteCap(): void
    {
        $sanitizer = new EnshrinedSvgSanitizer(maxBytes: 100);

        Expect::exception(SvgSanitizationException::class)->withMessageContaining(
            'SVG document is 101 bytes, over the 100-byte limit',
        );

        $sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect/>' . str_repeat(' ', 48) . '</svg>',
        );
    }

    /**
     * Both caps are inclusive at the boundary: a document of exactly the
     * allowed bytes with exactly the allowed elements sanitizes cleanly.
     */
    public function acceptsADocumentExactlyAtBothCaps(): void
    {
        $atBytes = new EnshrinedSvgSanitizer(maxBytes: 100);
        $clean = $atBytes->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect/>' . str_repeat(' ', 47) . '</svg>',
        );

        Assert::string($clean)->contains('<rect');

        // The element cap counts every element the DOM reports — the root
        // included: svg + rect + circle is exactly three.
        $atElements = new EnshrinedSvgSanitizer(maxElements: 3);
        $clean = $atElements->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/><circle r="1"/></svg>',
        );

        Assert::string($clean)->contains('<circle');
    }

    /**
     * The libxml error handler the post-pass installs is a resource held
     * across one load, not a process-wide setting: internal errors are off
     * again and no error leaks into the global list afterwards.
     */
    public function leavesLibxmlErrorStateRestored(): void
    {
        $previous = libxml_use_internal_errors(use_errors: false);

        try {
            $this->sanitizer->sanitize(
                '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>',
            );

            Assert::false(libxml_use_internal_errors());
            Assert::same(libxml_get_errors(), []);
        } finally {
            libxml_use_internal_errors($previous);
            libxml_clear_errors();
        }
    }

    public function rejectsADocumentOverTheElementCap(): void
    {
        $sanitizer = new EnshrinedSvgSanitizer(maxElements: 5);

        Expect::exception(SvgSanitizationException::class)->withMessageContaining('5-element limit');

        $sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('<rect width="1" height="1"/>', 10) . '</svg>',
        );
    }

    public function rejectsBytesThatAreNotSvg(): void
    {
        Expect::exception(SvgSanitizationException::class);

        $this->sanitizer->sanitize('just some text, no markup at all');
    }

    /**
     * When the library itself throws, the wrapper names it as the cause —
     * the operator has to see whether the input was hopeless or the
     * sanitizer misconfigured.
     */
    public function aLibraryFailureIsReportedWithItsCause(): void
    {
        // A document with no svg root is the input shape that makes the
        // library throw rather than return false — the wrapper's catch arm.
        $noSvgRoot = str_repeat('<g>', 60) . '<rect width="1" height="1"/>' . str_repeat('</g>', 60);

        try {
            $this->sanitizer->sanitize($noSvgRoot);
        } catch (SvgSanitizationException $exception) {
            Assert::true($exception->getPrevious() instanceof \Exception);

            return;
        }

        throw new \RuntimeException('expected an SvgSanitizationException');
    }

    public function rejectsXmlWhoseRootIsNotSvg(): void
    {
        Expect::exception(SvgSanitizationException::class);

        $this->sanitizer->sanitize('<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>');
    }
}
