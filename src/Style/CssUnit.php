<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * CSS length / angle / time unit, strongly typed. This type owns its own static
 * lookup: {@see ALL} is the source of the unit tokens, and each case is backed by
 * the NUMERIC INDEX into it (the wire id used by the NUM value types). `unitless`
 * (index 0) is a raw number (z-index, opacity, …). Resolve the token with {@see css()}.
 *
 * APPEND-ONLY — the index is the wire id, so never renumber cases or reorder ALL.
 *
 * Usage:
 *   CssUnit::px               // ->value === 1 (wire id)
 *   CssUnit::percent->css()   // '%'
 */
enum CssUnit: int
{
    case unitless = 0;  // 
    case px = 1;
    case percent = 2;  // %
    case em = 3;
    case rem = 4;
    case pt = 5;
    case vh = 6;
    case vw = 7;
    case vmin = 8;
    case vmax = 9;
    case ch = 10;
    case ex = 11;
    case cm = 12;
    case mm = 13;
    case in = 14;
    case pc = 15;
    case q = 16;  // Q
    case fr = 17;
    case deg = 18;
    case rad = 19;
    case grad = 20;
    case turn = 21;
    case s = 22;
    case ms = 23;
    case dpi = 24;
    case dpcm = 25;
    case dppx = 26;
    case x = 27;
    case hz = 28;  // Hz
    case kHz = 29;
    case lh = 30;
    case rlh = 31;

    /** All unit tokens, indexed by wire id. Source of truth for this type. */
    public const ALL = [
        '', 'px', '%', 'em', 'rem', 'pt', 'vh', 'vw', 'vmin', 'vmax', 'ch', 'ex', 'cm',
        'mm', 'in', 'pc', 'Q', 'fr', 'deg', 'rad', 'grad', 'turn', 's', 'ms', 'dpi',
        'dpcm', 'dppx', 'x', 'Hz', 'kHz', 'lh', 'rlh',
    ];

    /** The unit token for this case. */
    public function css(): string
    {
        return self::ALL[$this->value];
    }
}
