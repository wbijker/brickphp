<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * Bridges the UI builders' legacy utility strings to self-describing
 * {@see CssClass} names. Every builder funnels its declarations through
 * `TagDomNode::style($class, $css)`, where `$class` carries the variant prefix
 * (`md:hover:bg-red-500`) and `$css` is the resolved declarations. This turns
 * that pair into one atomic self-describing class name per declaration —
 * `md_hover_bgc-[#ef4444]` — from which the sheet (server) and the JS decoder
 * (client) both reconstruct the rule. The legacy BASE name is discarded: the
 * declarations, not the base, are the source of truth.
 */
final class Utility
{
    /** UI breakpoint / colour-scheme token → {@see CssMedia} case. */
    private const MEDIA = [
        'sm' => CssMedia::sm, 'md' => CssMedia::md, 'lg' => CssMedia::lg,
        'xl' => CssMedia::xl, '2xl' => CssMedia::xxl,
        'dark' => CssMedia::dark, 'light' => CssMedia::light,
    ];

    /** UI pseudo token → {@see CssPseudoClass} case. */
    private const PSEUDO_CLASS = [
        'hover' => CssPseudoClass::hover, 'active' => CssPseudoClass::active,
        'focus' => CssPseudoClass::focus, 'focus-visible' => CssPseudoClass::focusVisible,
        'focus-within' => CssPseudoClass::focusWithin, 'visited' => CssPseudoClass::visited,
        'disabled' => CssPseudoClass::disabled, 'enabled' => CssPseudoClass::enabled,
        'checked' => CssPseudoClass::checked, 'required' => CssPseudoClass::required,
        'valid' => CssPseudoClass::valid, 'invalid' => CssPseudoClass::invalid,
        'empty' => CssPseudoClass::empty, 'first' => CssPseudoClass::firstChild,
        'last' => CssPseudoClass::lastChild, 'only' => CssPseudoClass::onlyChild,
    ];

    /** UI pseudo token → {@see CssPseudoElement} case. */
    private const PSEUDO_ELEMENT = ['placeholder' => CssPseudoElement::placeholder];

    /** UI pseudo token → functional-pseudo token (`:nth-child(odd)` etc.). */
    private const FUNCTIONAL = ['odd' => 'nth-[odd]', 'even' => 'nth-[even]'];

    /**
     * One self-describing class name per declaration.
     *
     * @param array<string,string> $css property → value
     * @return list<string>
     */
    public static function names(string $class, array $css): array
    {
        $variants = self::variantTokens($class);
        $names = [];
        foreach ($css as $property => $value) {
            $names[] = self::build($property, (string) $value, $variants)->name();
        }
        return $names;
    }

    /** @param list<string> $variants */
    private static function build(string $property, string $value, array $variants): CssClass
    {
        $prop = CssProperty::fromCss($property);
        $val = CssStaticValue::raw($value);
        $class = $prop !== null ? CssClass::of($prop, $val) : CssClass::raw($property, $val);

        foreach ($variants as $token) {
            if (isset(self::MEDIA[$token])) {
                $class->media(self::MEDIA[$token]);
            } elseif (isset(self::PSEUDO_CLASS[$token])) {
                $class->pseudo(self::PSEUDO_CLASS[$token]);
            } elseif (isset(self::PSEUDO_ELEMENT[$token])) {
                $class->element(self::PSEUDO_ELEMENT[$token]);
            } elseif (isset(self::FUNCTIONAL[$token])) {
                $class->rawPseudo(self::FUNCTIONAL[$token]);
            } elseif (preg_match('/^(has|nth|not)-\[.+\]$/', $token)) {
                $class->rawPseudo($token);
            }
            // Unknown variant tokens are dropped (nothing sensible to emit).
        }
        return $class;
    }

    /**
     * The variant tokens before the base class — split on ':' but respecting
     * `[...]` brackets (so `has-[img]:p-4` keeps `has-[img]` whole). The base
     * (last part) is dropped.
     *
     * @return list<string>
     */
    private static function variantTokens(string $class): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        for ($i = 0, $len = strlen($class); $i < $len; $i++) {
            $ch = $class[$i];
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
            }
            if ($ch === ':' && $depth === 0) {
                if ($current !== '') {
                    $parts[] = $current;
                }
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }
        array_pop($parts);   // drop the base class name
        return $parts;
    }
}
