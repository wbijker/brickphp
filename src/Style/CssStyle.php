<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * Style API — SKELETON.
 *
 * A fluent builder for one CSS rule: a set of typed declarations plus (later)
 * any pseudo-class / pseudo-element wrappers. It sits ABOVE the binary codec —
 * declarations are collected as (CssProperty, value) pairs, which the codec
 * turns into wire bytes.
 *
 * Intentionally minimal — flesh out the bodies. Design notes inline.
 */
final class CssStyle
{
    /** @var list<array{property: CssProperty, value: CssDeclarationValue}> */
    private array $declarations = [];

    /**
     * Add one declaration.
     *
     *   $this->add(CssProperty::display, CssStaticValue::none);
     *   $this->add(CssProperty::padding, CssStaticValue::numeric(12, CssUnit::px));
     */
    public function add(CssProperty $property, CssDeclarationValue $value): static
    {
        $this->declarations[] = ['property' => $property, 'value' => $value];
        return $this;
    }

    /** @return list<array{property: CssProperty, value: CssDeclarationValue}> */
    public function declarations(): array
    {
        return $this->declarations;
    }
}
