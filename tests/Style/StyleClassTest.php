<?php

declare(strict_types=1);

namespace BrickPHP\Tests\Style;

use BrickPHP\Style\CssClass;
use BrickPHP\Style\CssColor;
use BrickPHP\Style\CssCombinator;
use BrickPHP\Style\CssMedia;
use BrickPHP\Style\CssProperty;
use BrickPHP\Style\CssPseudoClass;
use BrickPHP\Style\CssPseudoElement;
use BrickPHP\Style\CssStaticValue;
use BrickPHP\Style\CssSupports;
use BrickPHP\Style\CssUnit;
use BrickPHP\Style\Utility;
use PHPUnit\Framework\TestCase;

/**
 * The self-describing class-name codec: names round-trip back to the rule via
 * the shared static tables (the exact contract the JS decoder mirrors).
 */
final class StyleClassTest extends TestCase
{
    public function testKeywordDeclaration(): void
    {
        $c = CssClass::of(CssProperty::display, CssStaticValue::flex);
        $this->assertSame('d-[flex]', $c->name());
        $this->assertSame('.d-\\[flex\\]{display:flex}', $c->toCss());
    }

    public function testNumberWithUnit(): void
    {
        $c = CssClass::of(CssProperty::padding, CssStaticValue::numeric(12, CssUnit::px));
        $this->assertSame('p-[12px]', $c->name());
        $this->assertSame('padding', CssClass::decode('p-[12px]')['propertyName']);
        $this->assertSame('12px', CssClass::decode('p-[12px]')['value']);
    }

    public function testHexColour(): void
    {
        $c = CssClass::of(CssProperty::backgroundColor, CssColor::hex(0xFF00F2));
        $this->assertSame('bgc-[#ff00f2]', $c->name());
        $this->assertSame('.bgc-\\[\\#ff00f2\\]{background-color:#ff00f2}', $c->toCss());
    }

    public function testValueWithSpaceUsesUnderscore(): void
    {
        $c = CssClass::of(CssProperty::margin, CssStaticValue::raw('0 auto'));
        $this->assertSame('m-[0_auto]', $c->name());
        // underscore decodes back to a space, losslessly.
        $this->assertSame('0 auto', CssClass::decode('m-[0_auto]')['value']);
    }

    public function testArbitraryValue(): void
    {
        $c = CssClass::of(CssProperty::width, CssStaticValue::raw('calc(100% - 2rem)'));
        $this->assertSame('w-[calc(100%_-_2rem)]', $c->name());
        $this->assertSame('calc(100% - 2rem)', CssClass::decode($c->name())['value']);
    }

    public function testPseudoClass(): void
    {
        $c = CssClass::of(CssProperty::color, CssColor::hex(0x3B82F6))->pseudo(CssPseudoClass::hover);
        $this->assertSame('hover_c-[#3b82f6]', $c->name());
        $this->assertSame('.hover_c-\\[\\#3b82f6\\]:hover{color:#3b82f6}', $c->toCss());
    }

    public function testPseudoElement(): void
    {
        $c = CssClass::of(CssProperty::content, CssStaticValue::raw('""'))
            ->element(CssPseudoElement::before);
        $this->assertSame('before_ct-[""]', $c->name());
        $this->assertStringContainsString('::before{content:""}', $c->toCss());
    }

    public function testMediaAndPseudoCombined(): void
    {
        $c = CssClass::of(CssProperty::backgroundColor, CssStaticValue::white)
            ->media(CssMedia::md)->pseudo(CssPseudoClass::hover);
        $this->assertSame('md_hover_bgc-[white]', $c->name());
        $this->assertSame(
            '@media (min-width: 768px){.md_hover_bgc-\\[white\\]:hover{background-color:white}}',
            $c->toCss(),
        );
    }

    public function testMediaOrderIsCanonical(): void
    {
        $a = CssClass::of(CssProperty::color, CssStaticValue::white)->media(CssMedia::dark, CssMedia::lg);
        $b = CssClass::of(CssProperty::color, CssStaticValue::white)->media(CssMedia::lg, CssMedia::dark);
        $this->assertSame($a->name(), $b->name());
        $this->assertSame('lg_dark_c-[white]', $a->name());
        $this->assertSame(
            '@media (min-width: 1024px) and (prefers-color-scheme: dark)',
            '@media ' . implode(' and ', array_map(
                static fn(CssMedia $m) => $m->condition(),
                CssClass::decode($a->name())['media'],
            )),
        );
    }

    public function testSupports(): void
    {
        $c = CssClass::of(CssProperty::display, CssStaticValue::grid)->supports(CssSupports::grid);
        $this->assertSame('supports-grid_d-[grid]', $c->name());
        $this->assertSame(
            '@supports (display: grid){.supports-grid_d-\\[grid\\]{display:grid}}',
            $c->toCss(),
        );
    }

    public function testStructuralCombinator(): void
    {
        $c = CssClass::of(CssProperty::marginTop, CssStaticValue::numeric(1, CssUnit::rem))
            ->combinator(CssCombinator::child);
        $this->assertSame('child_mt-[1rem]', $c->name());
        $this->assertSame('.child_mt-\\[1rem\\] > *{margin-top:1rem}', $c->toCss());
    }

    public function testRelationalCombinator(): void
    {
        $c = CssClass::of(CssProperty::backgroundColor, CssStaticValue::white)
            ->combinator(CssCombinator::groupHover);
        $this->assertSame('group-hover_bgc-[white]', $c->name());
        $this->assertSame('.group:hover .group-hover_bgc-\\[white\\]{background-color:white}', $c->toCss());
    }

    /** Everything at once, and the full parse round-trips. */
    public function testFullStackRoundTrip(): void
    {
        $c = CssClass::of(CssProperty::color, CssColor::hex(0x112233))
            ->media(CssMedia::lg, CssMedia::dark)
            ->supports(CssSupports::grid)
            ->pseudo(CssPseudoClass::hover, CssPseudoClass::focus)
            ->element(CssPseudoElement::before);

        $name = $c->name();
        $p = CssClass::decode($name);

        $this->assertSame([CssMedia::lg, CssMedia::dark], $p['media']);
        $this->assertSame([CssSupports::grid], $p['supports']);
        $this->assertSame([CssPseudoClass::hover, CssPseudoClass::focus], $p['pseudoClasses']);
        $this->assertSame(CssPseudoElement::before, $p['pseudoElement']);
        $this->assertSame(CssProperty::color, $p['property']);
        $this->assertSame('#112233', $p['value']);
    }

    public function testDecodeRejectsUnknownVariant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CssClass::decode('bogus_p-[1px]');
    }

    public function testAliasesAreUniqueAndComplete(): void
    {
        $this->assertCount(count(CssProperty::ALL), CssProperty::ALIASES);
        $this->assertCount(count(CssProperty::ALIASES), array_unique(CssProperty::ALIASES));
        // every alias resolves back to its property
        foreach (CssProperty::cases() as $prop) {
            $this->assertSame($prop, CssProperty::fromAlias($prop->alias()));
        }
    }

    /** A property outside the enum uses its kebab name as the alias and round-trips. */
    public function testRawProperty(): void
    {
        $c = CssClass::raw('fill', CssStaticValue::raw('#ef4444'))->media(CssMedia::md);
        $this->assertSame('md_fill-[#ef4444]', $c->name());
        $this->assertSame(
            '@media (min-width: 768px){.md_fill-\\[\\#ef4444\\]{fill:#ef4444}}',
            $c->toCss(),
        );
        $this->assertSame('fill', CssClass::decode('fill-[#ef4444]')['propertyName']);
    }

    /** Functional pseudos (has-[…]/nth-[…]/not-[…]) carry their own brackets. */
    public function testFunctionalPseudo(): void
    {
        $c = CssClass::of(CssProperty::padding, CssStaticValue::raw('1rem'))
            ->pseudo(CssPseudoClass::hover)->rawPseudo('has-[img]');
        $this->assertSame('hover_has-[img]_p-[1rem]', $c->name());
        $this->assertSame('.hover_has-\\[img\\]_p-\\[1rem\\]:hover:has(img){padding:1rem}', $c->toCss());
        // The value is the FINAL bracket group, not the functional pseudo's.
        $this->assertSame('1rem', CssClass::decode($c->name())['value']);
    }

    public function testSheetSkipsNonUtilityNames(): void
    {
        $sheet = CssClass::sheet(['user-class', 'p-[4px]', 'not a class']);
        $this->assertSame('.p-\\[4px\\]{padding:4px}', $sheet);
    }

    /** The UI funnel: (prefixed class, declarations) → self-describing names. */
    public function testUtilityConversion(): void
    {
        $this->assertSame(['bgc-[#ef4444]'], Utility::names('bg-red-500', ['background-color' => '#ef4444']));
        $this->assertSame(['md_hover_bgc-[#fff]'], Utility::names('md:hover:bg-x', ['background-color' => '#fff']));
        // Multi-declaration → multiple atomic classes.
        $this->assertSame(['bw-[2px]', 'bs-[solid]'], Utility::names('border-2', ['border-width' => '2px', 'border-style' => 'solid']));
        // '2xl' maps to the xxl breakpoint; odd → functional nth-child.
        $this->assertSame(['xxl_w-[100%]'], Utility::names('2xl:w-full', ['width' => '100%']));
        $this->assertSame(['nth-[odd]_bgc-[#eee]'], Utility::names('odd:bg-x', ['background-color' => '#eee']));
        // A space in the value becomes '_'.
        $this->assertSame(['shadow-[0_0_0_3px_currentColor]'], Utility::names('shadow', ['box-shadow' => '0 0 0 3px currentColor']));
    }
}
