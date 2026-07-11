<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * CSS PACKER — CODEC INDEX TABLES (PHP port of definitions.js)
 *
 * The string lookups now live with their types — {@see CssProperty::ALL},
 * {@see CssStaticValue::ALL}, {@see CssColor::ALL}, {@see CssUnit::ALL},
 * {@see CssPseudoClass::ALL}, {@see CssPseudoElement::ALL}. This class holds the
 * remaining codec tables, which reference those by INDEX:
 *
 *   PROPS_64      → indices into CssProperty::ALL   (compact 2-byte property form)
 *   VALUES_80     → indices into CssStaticValue::ALL (5 static value banks)
 *   PAIRS_128     → [propIdx, valueIdx] tuples       (whole-declaration 1-byte form)
 *   PALETTE       → [colorIdx, hue, sat]             (gradient palette base colours)
 *   UNITS7        → indices into CssUnit::ALL         (3-bit common-unit codes)
 *
 * plus the numeric/fraction/pseudo tables. Indices are the wire format:
 * APPEND-ONLY — never reorder or remove entries.
 *
 * Capacity limits mirror the byte layout:
 *   PROPS_64 <= 64 · VALUES_80 <= 80 (5 banks × 16) · PAIRS_128 <= 128
 *   UNITS7 <= 7 · NUM_SCALE16 <= 16 · FRACTIONS_16 <= 16
 *   PALETTE <= 48 (3 banks × 16) · GRADIENT_LEVELS <= 16
 */
final class StyleDefinitions
{
    /* ------------------------------------------------------------------------
     * PROPS_64 — the 64 most-used properties, as INDICES into CssProperty::ALL.
     *     These get the 2-byte `10xx xxxx` form. Ordered roughly by real-world
     *     usage frequency.
     * ----------------------------------------------------------------------*/
    public const PROPS_64 = [
          0 /* display */,                 68 /* color */,                   14 /* width */,
         15 /* height */,                  20 /* margin */,                  25 /* padding */,
          1 /* position */,                71 /* font-size */,              103 /* background */,
        104 /* background-color */,       113 /* border */,                  79 /* text-align */,
         72 /* font-weight */,              2 /* top */,                       5 /* left */,
          3 /* right */,                    4 /* bottom */,                    6 /* float */,
         76 /* line-height */,             70 /* font-family */,               9 /* overflow */,
        148 /* opacity */,                  8 /* z-index */,                 133 /* border-radius */,
         13 /* box-sizing */,              38 /* flex */,                    45 /* justify-content */,
         48 /* align-items */,             81 /* text-decoration */,        105 /* background-image */,
        174 /* cursor */,                 158 /* transition */,             152 /* transform */,
         12 /* visibility */,              89 /* white-space */,             95 /* vertical-align */,
         21 /* margin-top */,              23 /* margin-bottom */,           24 /* margin-left */,
         22 /* margin-right */,            26 /* padding-top */,             28 /* padding-bottom */,
         29 /* padding-left */,            27 /* padding-right */,          116 /* border-color */,
        114 /* border-width */,           115 /* border-style */,            16 /* min-width */,
         17 /* max-width */,               18 /* min-height */,               19 /* max-height */,
         77 /* letter-spacing */,         146 /* box-shadow */,             173 /* content */,
         42 /* flex-direction */,          96 /* list-style */,             141 /* outline */,
         91 /* word-wrap */,               85 /* text-transform */,         108 /* background-size */,
        107 /* background-position */,    106 /* background-repeat */,      125 /* border-bottom */,
        117 /* border-top */,
    ];

    /* ------------------------------------------------------------------------
     * VALUES_80 — the 80 most-used values, as INDICES into CssStaticValue::ALL.
     *     Consumed by the five 1-byte STATIC_32_1..5 value types (16 each):
     *     bank b, payload y → VALUES_80[b*16 + y].
     * ----------------------------------------------------------------------*/
    public const VALUES_80 = [
        // — bank 1 (STATIC_32_1) —
        146 /* 0 */,          4 /* none */,         5 /* auto */,         41 /* center */,
        147 /* 1 */,          149 /* 100% */,       31 /* hidden */,      6 /* normal */,
        152 /* 100vh */,      30 /* visible */,     37 /* left */,        38 /* right */,
        63 /* nowrap */,      142 /* #fff */,       143 /* #000 */,       133 /* transparent */,
        // — bank 2 (STATIC_32_2) —
        46 /* flex-start */,  47 /* flex-end */,    113 /* cover */,      114 /* contain */,
        7 /* block */,        10 /* flex */,        8 /* inline */,       9 /* inline-block */,
        12 /* grid */,        11 /* inline-flex */, 14 /* table */,       16 /* table-cell */,
        20 /* contents */,    24 /* relative */,    25 /* absolute */,    26 /* fixed */,
        // — bank 3 (STATIC_32_3) —
        0 /* inherit */,      1 /* initial */,      2 /* unset */,        3 /* revert */,
        13 /* inline-grid */, 15 /* inline-table */, 17 /* table-row */,  18 /* table-column */,
        19 /* list-item */,   21 /* flow-root */,   22 /* run-in */,      23 /* static */,
        27 /* sticky */,      28 /* border-box */,  29 /* content-box */, 32 /* scroll */,
        // — bank 4 (STATIC_32_4) —
        33 /* clip */,        34 /* collapse */,    35 /* separate */,    36 /* both */,
        39 /* top */,         40 /* bottom */,      42 /* middle */,      43 /* justify */,
        44 /* start */,       45 /* end */,         48 /* space-between */, 49 /* space-around */,
        50 /* space-evenly */, 51 /* stretch */,    52 /* baseline */,    53 /* first baseline */,
        // — bank 5 (STATIC_32_5) —
        54 /* last baseline */, 55 /* safe */,      56 /* unsafe */,      57 /* row */,
        58 /* row-reverse */, 59 /* column */,      60 /* column-reverse */, 61 /* wrap */,
        62 /* wrap-reverse */, 64 /* bold */,       65 /* bolder */,      66 /* lighter */,
        67 /* 100 */,         68 /* 200 */,         69 /* 300 */,         70 /* 400 */,
    ];

    /* ------------------------------------------------------------------------
     * PAIRS_128 — the 128 most-used complete `property: value` declarations.
     *    These get the cheapest 1-byte `0xxx xxxx` form. Stored as
     *    [propIdx, valueIdx] tuples (indices into CssProperty::ALL /
     *    CssStaticValue::ALL); the array index is the encoded byte.
     * ----------------------------------------------------------------------*/
    public const PAIRS_128 = [
        [0, 7],    /* 0: display: block */
        [0, 10],   /* 1: display: flex */
        [0, 4],    /* 2: display: none */
        [0, 8],    /* 3: display: inline */
        [0, 9],    /* 4: display: inline-block */
        [0, 12],   /* 5: display: grid */
        [0, 11],   /* 6: display: inline-flex */
        [0, 14],   /* 7: display: table */
        [0, 16],   /* 8: display: table-cell */
        [0, 20],   /* 9: display: contents */
        [1, 24],   /* 10: position: relative */
        [1, 25],   /* 11: position: absolute */
        [1, 26],   /* 12: position: fixed */
        [1, 23],   /* 13: position: static */
        [1, 27],   /* 14: position: sticky */
        [13, 28],  /* 15: box-sizing: border-box */
        [13, 29],  /* 16: box-sizing: content-box */
        [20, 146], /* 17: margin: 0 */
        [20, 5],   /* 18: margin: auto */
        [20, 153], /* 19: margin: 0 auto */
        [25, 146], /* 20: padding: 0 */
        [21, 146], /* 21: margin-top: 0 */
        [23, 146], /* 22: margin-bottom: 0 */
        [26, 146], /* 23: padding-top: 0 */
        [28, 146], /* 24: padding-bottom: 0 */
        [14, 149], /* 25: width: 100% */
        [14, 5],   /* 26: width: auto */
        [14, 151], /* 27: width: 100vw */
        [14, 146], /* 28: width: 0 */
        [15, 149], /* 29: height: 100% */
        [15, 5],   /* 30: height: auto */
        [15, 152], /* 31: height: 100vh */
        [15, 146], /* 32: height: 0 */
        [17, 149], /* 33: max-width: 100% */
        [18, 152], /* 34: min-height: 100vh */
        [16, 146], /* 35: min-width: 0 */
        [9, 31],   /* 36: overflow: hidden */
        [9, 5],    /* 37: overflow: auto */
        [9, 32],   /* 38: overflow: scroll */
        [9, 30],   /* 39: overflow: visible */
        [10, 31],  /* 40: overflow-x: hidden */
        [11, 5],   /* 41: overflow-y: auto */
        [79, 41],  /* 42: text-align: center */
        [79, 37],  /* 43: text-align: left */
        [79, 38],  /* 44: text-align: right */
        [79, 43],  /* 45: text-align: justify */
        [72, 64],  /* 46: font-weight: bold */
        [72, 6],   /* 47: font-weight: normal */
        [72, 70],  /* 48: font-weight: 400 */
        [72, 73],  /* 49: font-weight: 700 */
        [72, 72],  /* 50: font-weight: 600 */
        [72, 71],  /* 51: font-weight: 500 */
        [73, 76],  /* 52: font-style: italic */
        [73, 6],   /* 53: font-style: normal */
        [81, 4],   /* 54: text-decoration: none */
        [81, 78],  /* 55: text-decoration: underline */
        [85, 81],  /* 56: text-transform: uppercase */
        [85, 82],  /* 57: text-transform: lowercase */
        [85, 83],  /* 58: text-transform: capitalize */
        [89, 63],  /* 59: white-space: nowrap */
        [89, 85],  /* 60: white-space: pre */
        [89, 6],   /* 61: white-space: normal */
        [68, 142], /* 62: color: #fff */
        [68, 143], /* 63: color: #000 */
        [68, 0],   /* 64: color: inherit */
        [68, 135], /* 65: color: white */
        [68, 136], /* 66: color: black */
        [103, 4],  /* 67: background: none */
        [103, 133],/* 68: background: transparent */
        [104, 133],/* 69: background-color: transparent */
        [104, 142],/* 70: background-color: #fff */
        [104, 143],/* 71: background-color: #000 */
        [113, 4],  /* 72: border: none */
        [113, 146],/* 73: border: 0 */
        [133, 150],/* 74: border-radius: 50% */
        [133, 146],/* 75: border-radius: 0 */
        [138, 34], /* 76: border-collapse: collapse */
        [174, 93], /* 77: cursor: pointer */
        [174, 94], /* 78: cursor: default */
        [174, 95], /* 79: cursor: not-allowed */
        [174, 96], /* 80: cursor: text */
        [42, 57],  /* 81: flex-direction: row */
        [42, 59],  /* 82: flex-direction: column */
        [43, 61],  /* 83: flex-wrap: wrap */
        [43, 63],  /* 84: flex-wrap: nowrap */
        [38, 147], /* 85: flex: 1 */
        [45, 41],  /* 86: justify-content: center */
        [45, 48],  /* 87: justify-content: space-between */
        [45, 46],  /* 88: justify-content: flex-start */
        [45, 47],  /* 89: justify-content: flex-end */
        [45, 49],  /* 90: justify-content: space-around */
        [48, 41],  /* 91: align-items: center */
        [48, 46],  /* 92: align-items: flex-start */
        [48, 47],  /* 93: align-items: flex-end */
        [48, 51],  /* 94: align-items: stretch */
        [48, 52],  /* 95: align-items: baseline */
        [50, 41],  /* 96: align-self: center */
        [39, 147], /* 97: flex-grow: 1 */
        [40, 146], /* 98: flex-shrink: 0 */
        [6, 37],   /* 99: float: left */
        [6, 38],   /* 100: float: right */
        [6, 4],    /* 101: float: none */
        [7, 36],   /* 102: clear: both */
        [12, 31],  /* 103: visibility: hidden */
        [12, 30],  /* 104: visibility: visible */
        [148, 146],/* 105: opacity: 0 */
        [148, 147],/* 106: opacity: 1 */
        [175, 4],  /* 107: pointer-events: none */
        [175, 5],  /* 108: pointer-events: auto */
        [176, 4],  /* 109: user-select: none */
        [96, 4],   /* 110: list-style: none */
        [95, 42],  /* 111: vertical-align: middle */
        [95, 39],  /* 112: vertical-align: top */
        [95, 40],  /* 113: vertical-align: bottom */
        [34, 113], /* 114: object-fit: cover */
        [34, 114], /* 115: object-fit: contain */
        [8, 147],  /* 116: z-index: 1 */
        [8, 146],  /* 117: z-index: 0 */
        [8, 148],  /* 118: z-index: -1 */
        [108, 113],/* 119: background-size: cover */
        [108, 114],/* 120: background-size: contain */
        [106, 106],/* 121: background-repeat: no-repeat */
        [107, 41], /* 122: background-position: center */
        [87, 92],  /* 123: text-overflow: ellipsis */
        [91, 89],  /* 124: word-wrap: break-word */
        [173, 157],/* 125: content: "" */
        [141, 4],  /* 126: outline: none */
        [183, 4],  /* 127: appearance: none */
    ];

    /* ------------------------------------------------------------------------
     * UNITS7 — the 7 most common units, addressed by a 3-bit code (0..6) in the
     *     NUM payload nibble, as INDICES into CssUnit::ALL. Code 7 is the ESCAPE
     *     (real unit = a full CssUnit index in a trailing byte).
     * ----------------------------------------------------------------------*/
    public const UNITS7 = [
        0 /* '' */, 1 /* px */, 2 /* % */, 3 /* em */, 4 /* rem */, 6 /* vh */, 7 /* vw */,
    ];

    /* ------------------------------------------------------------------------
     * NUM_SCALE16 — the 16-value design-system scale for the NUM_SCALE type
     *     (p-1 … p-16). Values mirror Tailwind spacing (rem): p-1=0.25 … p-16=4.
     * ----------------------------------------------------------------------*/
    public const NUM_SCALE16 = [
        0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2,     // p-1  … p-8
        2.25, 2.5, 2.75, 3, 3.25, 3.5, 3.75, 4,     // p-9  … p-16
    ];

    /* ------------------------------------------------------------------------
     * FRACTIONS_16 — 16 most common fractions, for the 1-byte FRAC_I value type.
     *     The 4-bit payload indexes this table directly.
     * ----------------------------------------------------------------------*/
    public const FRACTIONS_16 = [
        '1/2', '1/3', '2/3', '1/4', '3/4', '1/5', '2/5', '3/5', '4/5', '1/6', '5/6', '1/8', '3/8', '5/8', '7/8', '16/9',
    ];

    /* ------------------------------------------------------------------------
     * GRADIENT_LEVELS — HSL lightness % steps (12) for the palette gradient. A
     *     palette colour's actual hex = hsl(hue, sat, GRADIENT_LEVELS[step]).
     * ----------------------------------------------------------------------*/
    public const GRADIENT_LEVELS = [0, 5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 95]; // 12 steps

    /* ------------------------------------------------------------------------
     * PALETTE — design-system colour palette for the COLOR "palette" subtypes
     *     (PAL1..PAL3). Each base colour is [colorIdx, hue, sat] where colorIdx
     *     points into CssColor::ALL. 48 base colours → 3 banks of 16.
     * ----------------------------------------------------------------------*/
    public const PALETTE = [
        // — bank 1 (PAL1) —                                    name (= CssColor::ALL[idx])
        [0, 0, 80],     [1, 8, 85],     [2, 9, 80],     [3, 16, 80],   // red, scarlet, tomato, coral
        [4, 30, 90],    [5, 42, 95],    [6, 48, 90],    [7, 55, 90],   // orange, amber, gold, yellow
        [8, 80, 80],    [9, 90, 75],    [10, 140, 70],  [11, 160, 75], // lime, chartreuse, green, emerald
        [12, 165, 60],  [13, 175, 70],  [14, 185, 85],  [15, 200, 85], // mint, teal, cyan, sky
        // — bank 2 (PAL2) —
        [16, 210, 85],  [17, 220, 80],  [18, 225, 85],  [19, 245, 70], // azure, blue, cobalt, indigo
        [20, 230, 70],  [21, 270, 75],  [22, 280, 70],  [23, 290, 65], // navy, violet, purple, grape
        [24, 300, 80],  [25, 312, 85],  [26, 330, 80],  [27, 345, 80], // fuchsia, magenta, pink, rose
        [28, 348, 83],  [29, 340, 80],  [30, 6, 90],    [31, 25, 55],  // crimson, ruby, salmon, brown
        // — bank 3 (PAL3) —
        [32, 20, 55],   [33, 25, 60],   [34, 50, 50],   [35, 80, 55],  // sienna, chocolate, khaki, olive
        [36, 140, 60],  [37, 165, 55],  [38, 174, 72],  [39, 180, 80], // forest, sea, turquoise, aqua
        [40, 213, 70],  [41, 260, 55],  [42, 300, 45],  [43, 320, 35], // denim, lavender, plum, mauve
        [44, 215, 20],  [45, 0, 0],     [46, 240, 6],   [47, 30, 10],  // slate, gray, zinc, stone
    ];

}
