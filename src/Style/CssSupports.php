<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * A `@supports` variant, strongly typed. The enum's STRING value is the token
 * used verbatim in a class name (`supports-grid`); it round-trips back to the
 * feature query via {@see condition()}. Several supports variants on one class
 * combine into a single `@supports … and …` query (see {@see CssClass}).
 *
 * Source of truth for the supports token↔condition mapping the JS decoder
 * mirrors. APPEND-ONLY — a token is part of the class-name contract.
 *
 * Usage:
 *   CssSupports::grid                // token 'supports-grid'
 *   CssSupports::grid->condition()   // '(display: grid)'
 */
enum CssSupports: string
{
    case grid = 'supports-grid';                 // (display: grid)
    case flex = 'supports-flex';                 // (display: flex)
    case sticky = 'supports-sticky';             // (position: sticky)
    case gap = 'supports-gap';                   // (gap: 1rem)
    case subgrid = 'supports-subgrid';           // (grid-template-columns: subgrid)
    case backdrop = 'supports-backdrop';         // (backdrop-filter: blur(1px))
    case containerQueries = 'supports-container'; // (container-type: inline-size)
    case aspectRatio = 'supports-aspect';        // (aspect-ratio: 1)

    /** The feature-query fragment (no surrounding `@supports`). */
    public function condition(): string
    {
        return match ($this) {
            self::grid    => '(display: grid)',
            self::flex    => '(display: flex)',
            self::sticky  => '(position: sticky)',
            self::gap     => '(gap: 1rem)',
            self::subgrid => '(grid-template-columns: subgrid)',
            self::backdrop => '(backdrop-filter: blur(1px))',
            self::containerQueries => '(container-type: inline-size)',
            self::aspectRatio => '(aspect-ratio: 1)',
        };
    }
}
