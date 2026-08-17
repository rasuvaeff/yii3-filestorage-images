<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Svg;

use DOMDocument;
use DOMElement;
use enshrined\svgSanitize\Sanitizer;
use Exception;
use Override;
use Rasuvaeff\Yii3FilestorageImages\Exception\SvgSanitizationException;

/**
 * `enshrined/svg-sanitize` plus the checks it does not make.
 *
 * The library removes scripts, event handlers and — with remote references
 * off — external fetches. Three gaps stay open and are closed here, in order:
 * a byte cap before any parsing (XML entity work is quadratic-ish and the
 * sanitizer will happily chew a 200 MB "icon"), a post-pass stripping the
 * element classes that smuggle behaviour without being scripts
 * (`foreignObject` embeds arbitrary HTML; `animate`/`set`/`mpath` mutate
 * attributes after load, including `href`), and an element-count cap so a
 * million-element document does not become a client-side DoS wearing a clean
 * bill of health.
 *
 * @api
 */
final readonly class EnshrinedSvgSanitizer implements SvgSanitizerInterface
{
    private const array FORBIDDEN_ELEMENTS = [
        'foreignobject',
        'animate',
        'animatemotion',
        'animatetransform',
        'set',
        'mpath',
    ];

    /**
     * @param int<1, max> $maxBytes
     * @param int<1, max> $maxElements
     */
    public function __construct(
        private int $maxBytes = 2_097_152,
        private int $maxElements = 10_000,
    ) {}

    #[Override]
    public function sanitize(string $svg): string
    {
        if (\strlen($svg) > $this->maxBytes) {
            throw new SvgSanitizationException(
                'SVG document is ' . \strlen($svg) . " bytes, over the {$this->maxBytes}-byte limit",
            );
        }

        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(removeRemoteRefs: true);

        try {
            $clean = $sanitizer->sanitize($svg);
        } catch (Exception $exception) {
            // The library throws (LogicException, "expected exactly one svg
            // element") rather than returning false for structurally hopeless
            // input; either way the answer here is the same.
            throw new SvgSanitizationException('SVG document could not be parsed', previous: $exception);
        }
        if (!\is_string($clean) || $clean === '') {
            throw new SvgSanitizationException('SVG document could not be parsed');
        }

        return $this->postPass($clean);
    }

    /**
     * @param non-empty-string $svg Output of the library pass.
     *
     * @return non-empty-string
     *
     * @throws SvgSanitizationException
     */
    private function postPass(string $svg): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(use_errors: true);

        try {
            $loaded = $document->loadXML($svg, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;
        if (!$loaded || !$root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            throw new SvgSanitizationException('Sanitized document is not an SVG');
        }

        // The count runs before the strip so an absurd document fails fast
        // instead of paying a million removeChild() calls first.
        $elements = $document->getElementsByTagName('*');
        if ($elements->length > $this->maxElements) {
            throw new SvgSanitizationException(
                "SVG document has {$elements->length} elements, over the {$this->maxElements}-element limit",
            );
        }

        $forbidden = [];
        foreach ($elements as $element) {
            if (\in_array(strtolower($element->localName), self::FORBIDDEN_ELEMENTS, strict: true)) {
                $forbidden[] = $element;
            }
        }
        // Collected first, removed second: DOMNodeList is live, and removing
        // while iterating skips siblings.
        foreach ($forbidden as $element) {
            $element->parentNode?->removeChild($element);
        }

        $output = $document->saveXML($document->documentElement);
        if (!\is_string($output) || $output === '') {
            throw new SvgSanitizationException('Sanitized SVG could not be serialized');
        }

        return $output;
    }
}
