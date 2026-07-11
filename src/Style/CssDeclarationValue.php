<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * A value that can appear on the right of a CSS declaration. Implemented by the
 * keyword enum {@see CSSValue} and the literal holder {@see CssLiteral}; more
 * shapes (colour, fraction) can implement it later. The codec classifies each
 * into the cheapest VALUE_TYPE from its CSS string.
 *
 * Standalone (not co-located with an implementer) so PSR-4 can autoload it for
 * any implementing class, in any load order.
 */
interface CssDeclarationValue
{
    public function toCss(): string;
}
