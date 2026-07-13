<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * A `@media` variant, strongly typed. The enum's STRING value is the token used
 * verbatim in a class name (`md`, `dark`), so the token round-trips back to the
 * condition via {@see condition()}. Several media variants on one class combine
 * into a single `@media … and …` query (see {@see CssClass}).
 *
 * Source of truth for the media token↔condition mapping the JS decoder mirrors.
 * APPEND-ONLY — a token is part of the class-name contract.
 *
 * Usage:
 *   CssMedia::md                 // token 'md'
 *   CssMedia::md->condition()    // '(min-width: 768px)'
 */
enum CssMedia: string
{
    // Min-width breakpoints.
    case sm = 'sm';    // (min-width: 640px)
    case md = 'md';    // (min-width: 768px)
    case lg = 'lg';    // (min-width: 1024px)
    case xl = 'xl';    // (min-width: 1280px)
    case xxl = 'xxl';  // (min-width: 1536px)

    // Preference / device features.
    case dark = 'dark';                  // (prefers-color-scheme: dark)
    case light = 'light';                // (prefers-color-scheme: light)
    case motionReduce = 'motion-reduce'; // (prefers-reduced-motion: reduce)
    case motionSafe = 'motion-safe';     // (prefers-reduced-motion: no-preference)
    case portrait = 'portrait';          // (orientation: portrait)
    case landscape = 'landscape';        // (orientation: landscape)
    case print = 'print';                // print

    /** The media condition fragment (no surrounding `@media`). */
    public function condition(): string
    {
        return match ($this) {
            self::sm  => '(min-width: 640px)',
            self::md  => '(min-width: 768px)',
            self::lg  => '(min-width: 1024px)',
            self::xl  => '(min-width: 1280px)',
            self::xxl => '(min-width: 1536px)',
            self::dark  => '(prefers-color-scheme: dark)',
            self::light => '(prefers-color-scheme: light)',
            self::motionReduce => '(prefers-reduced-motion: reduce)',
            self::motionSafe   => '(prefers-reduced-motion: no-preference)',
            self::portrait  => '(orientation: portrait)',
            self::landscape => '(orientation: landscape)',
            self::print => 'print',
        };
    }

    /**
     * Canonical output rank — breakpoints first, then feature queries — so
     * `dark:lg` and `lg:dark` produce the same class name and the same `@media`.
     */
    public function rank(): int
    {
        return array_search($this, self::cases(), true);
    }
}
