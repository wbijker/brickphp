<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * A palette colour that encodes as the codec's COLOR "index" subtype — a single
 * wire byte, {@see index()}. Implemented by {@see CssColor} (named specials, as
 * enum cases) and {@see CssShade} (a family + shade). Kept in its own file so
 * PSR-4 can autoload it for either implementer.
 */
interface CssColorValue extends CssDeclarationValue
{
    /** The COLOR "index" wire byte (0..241 family shades, 242+ named specials). */
    public function index(): int;
}
