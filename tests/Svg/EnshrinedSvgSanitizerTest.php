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
        $sanitizer = new EnshrinedSvgSanitizer(maxBytes: 64);

        Expect::exception(SvgSanitizationException::class)->withMessageContaining('64-byte limit');

        $sanitizer->sanitize(
            '<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('<rect width="1" height="1"/>', 10) . '</svg>',
        );
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

    public function rejectsXmlWhoseRootIsNotSvg(): void
    {
        Expect::exception(SvgSanitizationException::class);

        $this->sanitizer->sanitize('<html xmlns="http://www.w3.org/1999/xhtml"><body>x</body></html>');
    }
}
