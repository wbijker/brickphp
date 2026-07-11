<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * Named CSS color, strongly typed. This type owns its own static lookup:
 * {@see ALL} is the source of the color names, and each case is backed by the
 * NUMERIC INDEX into it — the wire byte for the codec's COLOR "index" subtype.
 * Indices 0..47 also carry a gradient palette (see StyleDefinitions::PALETTE).
 *
 * APPEND-ONLY — the index is the wire id, so never renumber cases or reorder ALL.
 *
 * Usage:
 *   CssColor::crimson             // ->value === 28 (wire id)
 *   CssColor::currentColor->css() // 'currentColor'
 */
enum CssColor: int implements CssDeclarationValue
{
    case red = 0;
    case scarlet = 1;
    case tomato = 2;
    case coral = 3;
    case orange = 4;
    case amber = 5;
    case gold = 6;
    case yellow = 7;
    case lime = 8;
    case chartreuse = 9;
    case green = 10;
    case emerald = 11;
    case mint = 12;
    case teal = 13;
    case cyan = 14;
    case sky = 15;
    case azure = 16;
    case blue = 17;
    case cobalt = 18;
    case indigo = 19;
    case navy = 20;
    case violet = 21;
    case purple = 22;
    case grape = 23;
    case fuchsia = 24;
    case magenta = 25;
    case pink = 26;
    case rose = 27;
    case crimson = 28;
    case ruby = 29;
    case salmon = 30;
    case brown = 31;
    case sienna = 32;
    case chocolate = 33;
    case khaki = 34;
    case olive = 35;
    case forest = 36;
    case sea = 37;
    case turquoise = 38;
    case aqua = 39;
    case denim = 40;
    case lavender = 41;
    case plum = 42;
    case mauve = 43;
    case slate = 44;
    case gray = 45;
    case zinc = 46;
    case stone = 47;
    case transparent = 48;
    case currentColor = 49;
    case black = 50;
    case white = 51;
    case grey = 52;
    case silver = 53;
    case maroon = 54;

    /** All color names, indexed by wire id. Source of truth for this type. */
    public const ALL = [
        'red', 'scarlet', 'tomato', 'coral', 'orange', 'amber', 'gold', 'yellow', 'lime',
        'chartreuse', 'green', 'emerald', 'mint', 'teal', 'cyan', 'sky', 'azure', 'blue',
        'cobalt', 'indigo', 'navy', 'violet', 'purple', 'grape', 'fuchsia', 'magenta',
        'pink', 'rose', 'crimson', 'ruby', 'salmon', 'brown', 'sienna', 'chocolate',
        'khaki', 'olive', 'forest', 'sea', 'turquoise', 'aqua', 'denim', 'lavender',
        'plum', 'mauve', 'slate', 'gray', 'zinc', 'stone', 'transparent', 'currentColor',
        'black', 'white', 'grey', 'silver', 'maroon',
    ];

    /** The color name for this case. */
    public function css(): string
    {
        return self::ALL[$this->value];
    }

    public function toCss(): string
    {
        return $this->css();
    }
}
