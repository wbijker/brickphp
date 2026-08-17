<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * CSS property, strongly typed. This type owns its own static lookup: {@see ALL}
 * is the source of the property strings, and each case is backed by the NUMERIC
 * INDEX into it — which is also the property's wire id. Resolve the string with
 * {@see css()}.
 *
 * APPEND-ONLY — the index is the wire id, so never renumber cases or reorder ALL.
 *
 * Usage:
 *   CssProperty::display                 // ->value === 0 (wire id)
 *   CssProperty::paddingTop->css()       // 'padding-top'
 */
enum CssProperty: int
{
    case display = 0;
    case position = 1;
    case top = 2;
    case right = 3;
    case bottom = 4;
    case left = 5;
    case float = 6;
    case clear = 7;
    case zIndex = 8;  // z-index
    case overflow = 9;
    case overflowX = 10;  // overflow-x
    case overflowY = 11;  // overflow-y
    case visibility = 12;
    case boxSizing = 13;  // box-sizing
    case width = 14;
    case height = 15;
    case minWidth = 16;  // min-width
    case maxWidth = 17;  // max-width
    case minHeight = 18;  // min-height
    case maxHeight = 19;  // max-height
    case margin = 20;
    case marginTop = 21;  // margin-top
    case marginRight = 22;  // margin-right
    case marginBottom = 23;  // margin-bottom
    case marginLeft = 24;  // margin-left
    case padding = 25;
    case paddingTop = 26;  // padding-top
    case paddingRight = 27;  // padding-right
    case paddingBottom = 28;  // padding-bottom
    case paddingLeft = 29;  // padding-left
    case inset = 30;
    case insetBlock = 31;  // inset-block
    case insetInline = 32;  // inset-inline
    case aspectRatio = 33;  // aspect-ratio
    case objectFit = 34;  // object-fit
    case objectPosition = 35;  // object-position
    case resize = 36;
    case order = 37;
    case flex = 38;
    case flexGrow = 39;  // flex-grow
    case flexShrink = 40;  // flex-shrink
    case flexBasis = 41;  // flex-basis
    case flexDirection = 42;  // flex-direction
    case flexWrap = 43;  // flex-wrap
    case flexFlow = 44;  // flex-flow
    case justifyContent = 45;  // justify-content
    case justifyItems = 46;  // justify-items
    case justifySelf = 47;  // justify-self
    case alignItems = 48;  // align-items
    case alignContent = 49;  // align-content
    case alignSelf = 50;  // align-self
    case placeItems = 51;  // place-items
    case placeContent = 52;  // place-content
    case placeSelf = 53;  // place-self
    case gap = 54;
    case rowGap = 55;  // row-gap
    case columnGap = 56;  // column-gap
    case grid = 57;
    case gridTemplate = 58;  // grid-template
    case gridTemplateColumns = 59;  // grid-template-columns
    case gridTemplateRows = 60;  // grid-template-rows
    case gridTemplateAreas = 61;  // grid-template-areas
    case gridColumn = 62;  // grid-column
    case gridRow = 63;  // grid-row
    case gridArea = 64;  // grid-area
    case gridAutoFlow = 65;  // grid-auto-flow
    case gridAutoColumns = 66;  // grid-auto-columns
    case gridAutoRows = 67;  // grid-auto-rows
    case color = 68;
    case font = 69;
    case fontFamily = 70;  // font-family
    case fontSize = 71;  // font-size
    case fontWeight = 72;  // font-weight
    case fontStyle = 73;  // font-style
    case fontVariant = 74;  // font-variant
    case fontStretch = 75;  // font-stretch
    case lineHeight = 76;  // line-height
    case letterSpacing = 77;  // letter-spacing
    case wordSpacing = 78;  // word-spacing
    case textAlign = 79;  // text-align
    case textAlignLast = 80;  // text-align-last
    case textDecoration = 81;  // text-decoration
    case textDecorationLine = 82;  // text-decoration-line
    case textDecorationColor = 83;  // text-decoration-color
    case textDecorationStyle = 84;  // text-decoration-style
    case textTransform = 85;  // text-transform
    case textIndent = 86;  // text-indent
    case textOverflow = 87;  // text-overflow
    case textShadow = 88;  // text-shadow
    case whiteSpace = 89;  // white-space
    case wordBreak = 90;  // word-break
    case wordWrap = 91;  // word-wrap
    case overflowWrap = 92;  // overflow-wrap
    case writingMode = 93;  // writing-mode
    case direction = 94;
    case verticalAlign = 95;  // vertical-align
    case listStyle = 96;  // list-style
    case listStyleType = 97;  // list-style-type
    case listStylePosition = 98;  // list-style-position
    case listStyleImage = 99;  // list-style-image
    case tabSize = 100;  // tab-size
    case hyphens = 101;
    case webkitFontSmoothing = 102;  // -webkit-font-smoothing
    case background = 103;
    case backgroundColor = 104;  // background-color
    case backgroundImage = 105;  // background-image
    case backgroundRepeat = 106;  // background-repeat
    case backgroundPosition = 107;  // background-position
    case backgroundSize = 108;  // background-size
    case backgroundAttachment = 109;  // background-attachment
    case backgroundClip = 110;  // background-clip
    case backgroundOrigin = 111;  // background-origin
    case backgroundBlendMode = 112;  // background-blend-mode
    case border = 113;
    case borderWidth = 114;  // border-width
    case borderStyle = 115;  // border-style
    case borderColor = 116;  // border-color
    case borderTop = 117;  // border-top
    case borderTopWidth = 118;  // border-top-width
    case borderTopStyle = 119;  // border-top-style
    case borderTopColor = 120;  // border-top-color
    case borderRight = 121;  // border-right
    case borderRightWidth = 122;  // border-right-width
    case borderRightStyle = 123;  // border-right-style
    case borderRightColor = 124;  // border-right-color
    case borderBottom = 125;  // border-bottom
    case borderBottomWidth = 126;  // border-bottom-width
    case borderBottomStyle = 127;  // border-bottom-style
    case borderBottomColor = 128;  // border-bottom-color
    case borderLeft = 129;  // border-left
    case borderLeftWidth = 130;  // border-left-width
    case borderLeftStyle = 131;  // border-left-style
    case borderLeftColor = 132;  // border-left-color
    case borderRadius = 133;  // border-radius
    case borderTopLeftRadius = 134;  // border-top-left-radius
    case borderTopRightRadius = 135;  // border-top-right-radius
    case borderBottomRightRadius = 136;  // border-bottom-right-radius
    case borderBottomLeftRadius = 137;  // border-bottom-left-radius
    case borderCollapse = 138;  // border-collapse
    case borderSpacing = 139;  // border-spacing
    case borderImage = 140;  // border-image
    case outline = 141;
    case outlineWidth = 142;  // outline-width
    case outlineStyle = 143;  // outline-style
    case outlineColor = 144;  // outline-color
    case outlineOffset = 145;  // outline-offset
    case boxShadow = 146;  // box-shadow
    case boxDecorationBreak = 147;  // box-decoration-break
    case opacity = 148;
    case filter = 149;
    case backdropFilter = 150;  // backdrop-filter
    case mixBlendMode = 151;  // mix-blend-mode
    case transform = 152;
    case transformOrigin = 153;  // transform-origin
    case transformStyle = 154;  // transform-style
    case perspective = 155;
    case perspectiveOrigin = 156;  // perspective-origin
    case backfaceVisibility = 157;  // backface-visibility
    case transition = 158;
    case transitionProperty = 159;  // transition-property
    case transitionDuration = 160;  // transition-duration
    case transitionTimingFunction = 161;  // transition-timing-function
    case transitionDelay = 162;  // transition-delay
    case animation = 163;
    case animationName = 164;  // animation-name
    case animationDuration = 165;  // animation-duration
    case animationTimingFunction = 166;  // animation-timing-function
    case animationDelay = 167;  // animation-delay
    case animationIterationCount = 168;  // animation-iteration-count
    case animationDirection = 169;  // animation-direction
    case animationFillMode = 170;  // animation-fill-mode
    case animationPlayState = 171;  // animation-play-state
    case willChange = 172;  // will-change
    case content = 173;
    case cursor = 174;
    case pointerEvents = 175;  // pointer-events
    case userSelect = 176;  // user-select
    case touchAction = 177;  // touch-action
    case scrollBehavior = 178;  // scroll-behavior
    case scrollSnapType = 179;  // scroll-snap-type
    case scrollSnapAlign = 180;  // scroll-snap-align
    case caretColor = 181;  // caret-color
    case accentColor = 182;  // accent-color
    case appearance = 183;
    case clip = 184;
    case clipPath = 185;  // clip-path
    case mask = 186;
    case quotes = 187;
    case counterReset = 188;  // counter-reset
    case counterIncrement = 189;  // counter-increment
    case tableLayout = 190;  // table-layout
    case emptyCells = 191;  // empty-cells
    case isolation = 192;
    case webkitOverflowScrolling = 193;  // -webkit-overflow-scrolling
    case all = 194;

    /** All property strings, indexed by wire id. Source of truth for this type. */
    public const ALL = [
        'display', 'position', 'top', 'right', 'bottom', 'left', 'float', 'clear',
        'z-index', 'overflow', 'overflow-x', 'overflow-y', 'visibility', 'box-sizing',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height', 'margin',
        'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding',
        'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'inset',
        'inset-block', 'inset-inline', 'aspect-ratio', 'object-fit', 'object-position',
        'resize', 'order', 'flex', 'flex-grow', 'flex-shrink', 'flex-basis',
        'flex-direction', 'flex-wrap', 'flex-flow', 'justify-content', 'justify-items',
        'justify-self', 'align-items', 'align-content', 'align-self', 'place-items',
        'place-content', 'place-self', 'gap', 'row-gap', 'column-gap', 'grid',
        'grid-template', 'grid-template-columns', 'grid-template-rows',
        'grid-template-areas', 'grid-column', 'grid-row', 'grid-area', 'grid-auto-flow',
        'grid-auto-columns', 'grid-auto-rows', 'color', 'font', 'font-family', 'font-size',
        'font-weight', 'font-style', 'font-variant', 'font-stretch', 'line-height',
        'letter-spacing', 'word-spacing', 'text-align', 'text-align-last',
        'text-decoration', 'text-decoration-line', 'text-decoration-color',
        'text-decoration-style', 'text-transform', 'text-indent', 'text-overflow',
        'text-shadow', 'white-space', 'word-break', 'word-wrap', 'overflow-wrap',
        'writing-mode', 'direction', 'vertical-align', 'list-style', 'list-style-type',
        'list-style-position', 'list-style-image', 'tab-size', 'hyphens',
        '-webkit-font-smoothing', 'background', 'background-color', 'background-image',
        'background-repeat', 'background-position', 'background-size',
        'background-attachment', 'background-clip', 'background-origin',
        'background-blend-mode', 'border', 'border-width', 'border-style', 'border-color',
        'border-top', 'border-top-width', 'border-top-style', 'border-top-color',
        'border-right', 'border-right-width', 'border-right-style', 'border-right-color',
        'border-bottom', 'border-bottom-width', 'border-bottom-style',
        'border-bottom-color', 'border-left', 'border-left-width', 'border-left-style',
        'border-left-color', 'border-radius', 'border-top-left-radius',
        'border-top-right-radius', 'border-bottom-right-radius',
        'border-bottom-left-radius', 'border-collapse', 'border-spacing', 'border-image',
        'outline', 'outline-width', 'outline-style', 'outline-color', 'outline-offset',
        'box-shadow', 'box-decoration-break', 'opacity', 'filter', 'backdrop-filter',
        'mix-blend-mode', 'transform', 'transform-origin', 'transform-style',
        'perspective', 'perspective-origin', 'backface-visibility', 'transition',
        'transition-property', 'transition-duration', 'transition-timing-function',
        'transition-delay', 'animation', 'animation-name', 'animation-duration',
        'animation-timing-function', 'animation-delay', 'animation-iteration-count',
        'animation-direction', 'animation-fill-mode', 'animation-play-state',
        'will-change', 'content', 'cursor', 'pointer-events', 'user-select',
        'touch-action', 'scroll-behavior', 'scroll-snap-type', 'scroll-snap-align',
        'caret-color', 'accent-color', 'appearance', 'clip', 'clip-path', 'mask', 'quotes',
        'counter-reset', 'counter-increment', 'table-layout', 'empty-cells', 'isolation',
        '-webkit-overflow-scrolling', 'all',
    ];

    /**
     * Short self-describing class-name codes, indexed by wire id (parallel to
     * {@see ALL}). Each code is unique and is the token used in a class name's
     * declaration segment — `p-[12px]`, `bgc-[#fff]`. Source of truth for the
     * alias↔property mapping the JS decoder mirrors.
     *
     * APPEND-ONLY — a code is part of the class-name contract; never change or
     * reorder an existing entry.
     */
    public const ALIASES = [
        'd', 'pos', 'top', 'rt', 'bot', 'lft',
        'flt', 'clr', 'z', 'ov', 'ovx', 'ovy',
        'vis', 'bxs', 'w', 'h', 'minw', 'maxw',
        'minh', 'maxh', 'm', 'mt', 'mr', 'mb',
        'ml', 'p', 'pt', 'pr', 'pb', 'pl',
        'ins', 'ib', 'ii', 'ar', 'of', 'op',
        'r', 'ord', 'fx', 'fxg', 'fxs', 'fxb',
        'fxd', 'fxw', 'fxf', 'jc', 'ji', 'js',
        'ai', 'ac', 'as', 'pi', 'pc', 'ps',
        'gap', 'rgap', 'cgap', 'grid', 'gt', 'gtc',
        'gtr', 'gta', 'gcol', 'grow', 'garea', 'gaf',
        'gac', 'gar', 'c', 'font', 'ff', 'fs',
        'fw', 'fst', 'fv', 'fss', 'lh', 'ls',
        'ws', 'ta', 'tal', 'td', 'tdl', 'tdc',
        'tds', 'tt', 'ti', 'tov', 'tsh', 'wsp',
        'wb', 'ww', 'owr', 'wm', 'di', 'va',
        'lst', 'lstt', 'lsp', 'lsi', 'ts', 'hy',
        'wfs', 'bg', 'bgc', 'bgi', 'bgr', 'bgp',
        'bgs', 'bga', 'bgcl', 'bo', 'bbm', 'bd',
        'bw', 'bs', 'bc', 'bt', 'btw', 'bts',
        'btc', 'brt', 'brw', 'brs', 'brc', 'bb',
        'bbw', 'bbs', 'bbc', 'bl', 'blw', 'bls',
        'blc', 'rad', 'radtl', 'radtr', 'radbr', 'radbl',
        'bcol', 'bsp', 'bi', 'ol', 'ow', 'os',
        'oc', 'oo', 'shadow', 'bdb', 'o', 'fil',
        'bfil', 'mbm', 'tf', 'tfo', 'trst', 'psp',
        'po', 'bv', 'tr', 'tp', 'trdu', 'ttf',
        'trde', 'anim', 'an', 'ad', 'atf', 'ande',
        'aic', 'andi', 'afm', 'aps', 'wc', 'ct',
        'cur', 'pe', 'us', 'tac', 'sb', 'sst',
        'ssa', 'cc', 'acco', 'app', 'cl', 'cp',
        'ma', 'q', 'cr', 'ci', 'tl', 'ec',
        'iso', 'wos', 'all',
    ];

    /** The CSS property name for this case. */
    public function css(): string
    {
        return self::ALL[$this->value];
    }

    /** The short class-name code for this case ('padding' → 'p'). */
    public function alias(): string
    {
        return self::ALIASES[$this->value];
    }

    /** Resolve a class-name code back to its property ('p' → padding), or null. */
    public static function fromAlias(string $alias): ?self
    {
        static $index = null;
        $index ??= array_flip(self::ALIASES);
        $id = $index[$alias] ?? null;
        return $id === null ? null : self::from($id);
    }

    /** Resolve a CSS property name back to its case ('padding-top' → paddingTop), or null. */
    public static function fromCss(string $css): ?self
    {
        static $index = null;
        $index ??= array_flip(self::ALL);
        $id = $index[$css] ?? null;
        return $id === null ? null : self::from($id);
    }
}
