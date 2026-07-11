<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * Static CSS keyword value, strongly typed. This type owns its own static lookup:
 * {@see ALL} is the source of the value strings, and each case is backed by the
 * NUMERIC INDEX into it (the wire id). Cases cover the identifier-safe keywords;
 * ALL also holds the literals (numbers, hex, percentages) at their wire positions,
 * which are produced through the {@see numeric()} / {@see raw()} factories instead.
 *
 * APPEND-ONLY — the index is the wire id, so never renumber cases or reorder ALL.
 *
 * Usage:
 *   CssStaticValue::none                       // ->value === 4 (wire id)
 *   CssStaticValue::flexStart->css()           // 'flex-start'
 *   CssStaticValue::numeric(12, CssUnit::px)   // "12px"  (CssLiteral)
 *   CssStaticValue::raw('calc(100% - 2rem)')   // escape hatch (CssLiteral)
 */
enum CssStaticValue: int implements CssDeclarationValue
{
    case inherit = 0;
    case initial = 1;
    case unset = 2;
    case revert = 3;
    case none = 4;
    case auto = 5;
    case normal = 6;
    case block = 7;
    case inline = 8;
    case inlineBlock = 9;  // inline-block
    case flex = 10;
    case inlineFlex = 11;  // inline-flex
    case grid = 12;
    case inlineGrid = 13;  // inline-grid
    case table = 14;
    case inlineTable = 15;  // inline-table
    case tableCell = 16;  // table-cell
    case tableRow = 17;  // table-row
    case tableColumn = 18;  // table-column
    case listItem = 19;  // list-item
    case contents = 20;
    case flowRoot = 21;  // flow-root
    case runIn = 22;  // run-in
    case static = 23;
    case relative = 24;
    case absolute = 25;
    case fixed = 26;
    case sticky = 27;
    case borderBox = 28;  // border-box
    case contentBox = 29;  // content-box
    case visible = 30;
    case hidden = 31;
    case scroll = 32;
    case clip = 33;
    case collapse = 34;
    case separate = 35;
    case both = 36;
    case left = 37;
    case right = 38;
    case top = 39;
    case bottom = 40;
    case center = 41;
    case middle = 42;
    case justify = 43;
    case start = 44;
    case end = 45;
    case flexStart = 46;  // flex-start
    case flexEnd = 47;  // flex-end
    case spaceBetween = 48;  // space-between
    case spaceAround = 49;  // space-around
    case spaceEvenly = 50;  // space-evenly
    case stretch = 51;
    case baseline = 52;
    case firstBaseline = 53;  // first baseline
    case lastBaseline = 54;  // last baseline
    case safe = 55;
    case unsafe = 56;
    case row = 57;
    case rowReverse = 58;  // row-reverse
    case column = 59;
    case columnReverse = 60;  // column-reverse
    case wrap = 61;
    case wrapReverse = 62;  // wrap-reverse
    case nowrap = 63;
    case bold = 64;
    case bolder = 65;
    case lighter = 66;
    case italic = 76;
    case oblique = 77;
    case underline = 78;
    case overline = 79;
    case lineThrough = 80;  // line-through
    case uppercase = 81;
    case lowercase = 82;
    case capitalize = 83;
    case smallCaps = 84;  // small-caps
    case pre = 85;
    case preWrap = 86;  // pre-wrap
    case preLine = 87;  // pre-line
    case breakSpaces = 88;  // break-spaces
    case breakWord = 89;  // break-word
    case breakAll = 90;  // break-all
    case keepAll = 91;  // keep-all
    case ellipsis = 92;
    case pointer = 93;
    case default = 94;
    case notAllowed = 95;  // not-allowed
    case text = 96;
    case move = 97;
    case grab = 98;
    case grabbing = 99;
    case wait = 100;
    case help = 101;
    case crosshair = 102;
    case zoomIn = 103;  // zoom-in
    case progress = 104;
    case repeat = 105;
    case noRepeat = 106;  // no-repeat
    case repeatX = 107;  // repeat-x
    case repeatY = 108;  // repeat-y
    case round = 109;
    case space = 110;
    case fixedBg = 111;  // fixed-bg
    case local = 112;
    case cover = 113;
    case contain = 114;
    case fill = 115;
    case scaleDown = 116;  // scale-down
    case solid = 117;
    case dashed = 118;
    case dotted = 119;
    case double = 120;
    case groove = 121;
    case ridge = 122;
    case inset = 123;
    case outset = 124;
    case disc = 125;
    case circle = 126;
    case square = 127;
    case decimal = 128;
    case lowerAlpha = 129;  // lower-alpha
    case upperAlpha = 130;  // upper-alpha
    case all = 131;
    case painted = 132;
    case transparent = 133;
    case currentColor = 134;
    case white = 135;
    case black = 136;
    case red = 137;
    case green = 138;
    case blue = 139;
    case gray = 140;
    case silver = 141;
    case borderRadius = 155;  // border-radius
    case contentVisibility = 156;  // content-visibility

    /**
     * All static value strings, indexed by wire id. Source of truth for this type.
     * Includes literals with no case (e.g. '100', '#fff', '100%') at their positions.
     */
    public const ALL = [
        'inherit', 'initial', 'unset', 'revert', 'none', 'auto', 'normal', 'block',
        'inline', 'inline-block', 'flex', 'inline-flex', 'grid', 'inline-grid', 'table',
        'inline-table', 'table-cell', 'table-row', 'table-column', 'list-item', 'contents',
        'flow-root', 'run-in', 'static', 'relative', 'absolute', 'fixed', 'sticky',
        'border-box', 'content-box', 'visible', 'hidden', 'scroll', 'clip', 'collapse',
        'separate', 'both', 'left', 'right', 'top', 'bottom', 'center', 'middle',
        'justify', 'start', 'end', 'flex-start', 'flex-end', 'space-between',
        'space-around', 'space-evenly', 'stretch', 'baseline', 'first baseline',
        'last baseline', 'safe', 'unsafe', 'row', 'row-reverse', 'column',
        'column-reverse', 'wrap', 'wrap-reverse', 'nowrap', 'bold', 'bolder', 'lighter',
        '100', '200', '300', '400', '500', '600', '700', '800', '900', 'italic', 'oblique',
        'underline', 'overline', 'line-through', 'uppercase', 'lowercase', 'capitalize',
        'small-caps', 'pre', 'pre-wrap', 'pre-line', 'break-spaces', 'break-word',
        'break-all', 'keep-all', 'ellipsis', 'pointer', 'default', 'not-allowed', 'text',
        'move', 'grab', 'grabbing', 'wait', 'help', 'crosshair', 'zoom-in', 'progress',
        'repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'round', 'space', 'fixed-bg',
        'local', 'cover', 'contain', 'fill', 'scale-down', 'solid', 'dashed', 'dotted',
        'double', 'groove', 'ridge', 'inset', 'outset', 'disc', 'circle', 'square',
        'decimal', 'lower-alpha', 'upper-alpha', 'all', 'painted', 'transparent',
        'currentColor', 'white', 'black', 'red', 'green', 'blue', 'gray', 'silver', '#fff',
        '#000', '#fff0', '#f00', '0', '1', '-1', '100%', '50%', '100vw', '100vh', '0 auto',
        '1px', 'border-radius', 'content-visibility', '""',
    ];

    /** The CSS value string for this case. */
    public function css(): string
    {
        return self::ALL[$this->value];
    }

    public function toCss(): string
    {
        return $this->css();
    }

    /** A number with an optional unit: numeric(12, CssUnit::px) -> "12px". */
    public static function numeric(int|float $amount, CssUnit $unit = CssUnit::unitless): CssLiteral
    {
        return new CssLiteral($amount . $unit->css());
    }

    /** Escape hatch for values not yet modelled (calc(), gradients, shadows…). */
    public static function raw(string $css): CssLiteral
    {
        return new CssLiteral($css);
    }
}

/**
 * A resolved CSS value string (numbers, raw fragments). Kept as a string for now;
 * the codec re-classifies it at encode time, exactly as the JS codec does.
 */
final class CssLiteral implements CssDeclarationValue
{
    public function __construct(
        public readonly string $css,
    ) {}

    public function toCss(): string
    {
        return $this->css;
    }
}
