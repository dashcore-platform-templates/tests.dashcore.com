<?php

declare(strict_types=1);

namespace Dashcore\DesignKit\Testing;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Assert;

/**
 * Reads a rendered page the way the fleet's conformance check does.
 *
 * Each consuming app calls Shell::assertConforms() from its own suite against
 * a real authenticated page. A package test can only prove the component
 * renders; it cannot prove an app still uses it, and an app that quietly
 * forks its own sidebar is exactly the drift this package exists to stop.
 */
class Shell
{
    private DOMXPath $xpath;

    public function __construct(string $html)
    {
        $dom = new DOMDocument;

        // A page is HTML5 that libxml grumbles about (<svg>, boolean
        // attributes); the parse is fine, so drop the warnings.
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $this->xpath = new DOMXPath($dom);
    }

    public static function of(string $html): self
    {
        return new self($html);
    }

    /**
     * The page is drawn by the shared shell, and the menu appears exactly once.
     */
    public static function assertConforms(string $html): void
    {
        $shell = self::of($html);

        Assert::assertSame(1, $shell->count('//*[@data-dc-shell]'), 'The page is not drawn by <x-ds::shell>.');
        Assert::assertSame(1, $shell->count('//aside[@data-dc-sidebar]//nav[@data-dc-nav]'), 'The shell sidebar is missing its menu.');
        // Navigation inside <main> is the page's own — pagination, a guide's
        // contents, Flux's navlist on a settings page — and is fine. A <nav>
        // outside <main> that is not the shell's, or one anywhere calling
        // itself the main menu, is a second menu beside the shell: a fork.
        Assert::assertSame(
            0,
            $shell->count('//nav[not(@data-dc-nav)][not(ancestor::main)]'),
            'The page renders a <nav> outside <main> that is not the shell\'s — a menu beside the shell is a fork of it.',
        );
        Assert::assertSame(
            0,
            $shell->count('//nav[not(@data-dc-nav)][translate(normalize-space(@aria-label), "MAIN", "main") = "main"]'),
            'The page renders a second main menu — a menu beside the shell is a fork of it.',
        );
        Assert::assertSame(1, $shell->count('//input[@type="checkbox"][@data-dc-drawer]'), 'The drawer toggle is missing; below lg the menu would be unreachable.');
    }

    /**
     * The labels of the menu's section headings, in order.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        return $this->texts('//nav[@data-dc-nav]//p[contains(@class, "dc-label")] | //nav[@data-dc-nav]//summary');
    }

    /**
     * Every menu link label, in document order.
     *
     * @return list<string>
     */
    public function links(): array
    {
        return $this->labels('//nav[@data-dc-nav]//a[contains(@class, "dc-nav-item")]');
    }

    /**
     * Every menu link href, in document order.
     *
     * @return list<string>
     */
    public function hrefs(): array
    {
        return array_map(
            fn (DOMElement $a) => $a->getAttribute('href'),
            $this->elements('//nav[@data-dc-nav]//a[contains(@class, "dc-nav-item")][@href]'),
        );
    }

    /**
     * The link labels filed under one heading.
     *
     * @return list<string>
     */
    public function linksUnder(string $group): array
    {
        $headings = $this->elements('//nav[@data-dc-nav]//p[contains(@class, "dc-label")] | //nav[@data-dc-nav]//summary');

        foreach ($headings as $heading) {
            if (trim($heading->textContent) === $group) {
                // A plain heading sits in its own row above the list; a
                // folding one is the <summary> beside it.
                $section = $heading->nodeName === 'summary' ? $heading->parentNode : $heading->parentNode->parentNode;

                return array_map(
                    fn (DOMElement $a) => $this->labelOf($a),
                    iterator_to_array($this->xpath->query('.//a[contains(@class, "dc-nav-item")]', $section)),
                );
            }
        }

        return [];
    }

    /**
     * The labels the menu marks as the current page.
     *
     * @return list<string>
     */
    public function active(): array
    {
        return $this->labels('//nav[@data-dc-nav]//a[@aria-current="page"]');
    }

    public function version(): ?string
    {
        $shell = $this->elements('//*[@data-dc-shell]')[0] ?? null;

        return $shell?->hasAttribute('data-dc-ui') ? $shell->getAttribute('data-dc-ui') : null;
    }

    public function count(string $query): int
    {
        return $this->query($query)->length;
    }

    /**
     * An XPath that does not compile says so, rather than surfacing as
     * "property length on false" three frames away from the typo.
     */
    private function query(string $query): \DOMNodeList
    {
        $result = @$this->xpath->query($query);

        if ($result === false) {
            throw new \InvalidArgumentException("Not a valid XPath expression: {$query}");
        }

        return $result;
    }

    /**
     * A menu link's own label, without its hint or count.
     */
    private function labelOf(DOMElement $a): string
    {
        $label = $this->xpath->query('.//*[@data-dc-nav-label]', $a)->item(0);

        return trim(($label ?? $a)->textContent);
    }

    /**
     * @return list<string>
     */
    private function labels(string $query): array
    {
        return array_map(fn (DOMElement $a) => $this->labelOf($a), $this->elements($query));
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(string $query): array
    {
        return array_values(array_filter(
            iterator_to_array($this->query($query)),
            fn ($node) => $node instanceof DOMElement,
        ));
    }

    /**
     * @return list<string>
     */
    private function texts(string $query): array
    {
        return array_map(fn (DOMElement $el) => trim($el->textContent), $this->elements($query));
    }
}
