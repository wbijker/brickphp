<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * A collection of {@see CssClass} — the utility classes that make up an element's
 * styling. Each declaration added becomes one atomic class whose NAME is a self-
 * describing token chain that round-trips back to the rule (see {@see CssClass}).
 *
 *   $style = (new CssStyle())
 *       ->add(CssProperty::display, CssStaticValue::flex)
 *       ->add(CssProperty::padding, CssStaticValue::numeric(12, CssUnit::px));
 *   $style->names();      // ['d-[flex]', 'p-[12px]', …]
 *   (string) $style;      // "d-[flex] p-[12px] …"  — the HTML class attribute
 */
final class CssStyle
{
    /** @var list<CssClass> */
    private array $classes = [];

    /** Add one declaration as its own atomic class. */
    public function add(CssProperty $property, CssDeclarationValue $value): static
    {
        $this->classes[] = CssClass::of($property, $value);
        return $this;
    }

    /** Add a pre-built class (e.g. a multi-declaration or pseudo-wrapped one). */
    public function addClass(CssClass $class): static
    {
        $this->classes[] = $class;
        return $this;
    }

    /** @return list<CssClass> */
    public function classes(): array
    {
        return $this->classes;
    }

    /** @return list<string> the base64 class names */
    public function names(): array
    {
        return array_map(static fn(CssClass $c): string => $c->name(), $this->classes);
    }

    /** The space-separated class list for an HTML `class` attribute. */
    public function __toString(): string
    {
        return implode(' ', $this->names());
    }
}
