<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * Named CSS colours. The enum cases are the named SPECIALS (white, …) — usable
 * without parentheses, e.g. `CssColor::white`. Design-system FAMILY shades come
 * from the static factories, e.g. `CssColor::gray(60)`, which return a
 * {@see CssShade}. Both encode as the codec's COLOR "index" subtype (one byte):
 * the 242 family shades take indices 0..241 (`familyIndex * 11 + shadeIndex`),
 * named specials take the free slots from 242 up.
 *
 * A shade is an HSL LIGHTNESS percent (0 = black … 100 = white), so `gray(60)`
 * is `hsl(220, 9%, 60%)`.
 *
 * Usage:
 *   CssColor::white          // a named special (no parens)
 *   CssColor::gray(60)       // gray at 60% lightness  (→ CssShade)
 *   CssColor::blue()         // family default, shade 50
 *   CssColor::hex(0xFF0000)  // a non-palette hex colour (COLOR hex subtype)
 */
enum CssColor: int implements CssColorValue
{
    // Named specials — COLOR-index slots after the 242 family shades (0..241).
    case white = 242;

    /** 22 families in wire order → [hue, saturation%]. Shade supplies lightness. */
    public const FAMILIES = [
        'slate'   => [215, 16], 'gray'    => [220, 9],  'zinc'    => [240, 5],
        'neutral' => [0, 0],    'stone'   => [25, 6],   'red'     => [0, 84],
        'orange'  => [25, 95],  'amber'   => [38, 92],  'yellow'  => [48, 96],
        'lime'    => [85, 78],  'green'   => [142, 71], 'emerald' => [160, 84],
        'teal'    => [172, 66], 'cyan'    => [189, 94], 'sky'     => [199, 89],
        'blue'    => [217, 91], 'indigo'  => [239, 84], 'violet'  => [258, 90],
        'purple'  => [271, 81], 'fuchsia' => [292, 84], 'pink'    => [330, 81],
        'rose'    => [350, 89],
    ];

    /** 11 shade stops (each is an HSL lightness %). */
    public const SHADES = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

    // ---- named specials (this enum's cases) -------------------------------

    /** The COLOR "index" wire byte for a named special. */
    public function index(): int
    {
        return $this->value;
    }

    public function toCss(): string
    {
        return match ($this) {
            self::white => 'white',
        };
    }

    // ---- families (default shade 50) → CssShade ---------------------------

    public static function slate(int $shade = 50): CssShade   { return self::shade('slate', $shade); }
    public static function gray(int $shade = 50): CssShade    { return self::shade('gray', $shade); }
    public static function zinc(int $shade = 50): CssShade    { return self::shade('zinc', $shade); }
    public static function neutral(int $shade = 50): CssShade  { return self::shade('neutral', $shade); }
    public static function stone(int $shade = 50): CssShade   { return self::shade('stone', $shade); }
    public static function red(int $shade = 50): CssShade     { return self::shade('red', $shade); }
    public static function orange(int $shade = 50): CssShade  { return self::shade('orange', $shade); }
    public static function amber(int $shade = 50): CssShade   { return self::shade('amber', $shade); }
    public static function yellow(int $shade = 50): CssShade  { return self::shade('yellow', $shade); }
    public static function lime(int $shade = 50): CssShade    { return self::shade('lime', $shade); }
    public static function green(int $shade = 50): CssShade   { return self::shade('green', $shade); }
    public static function emerald(int $shade = 50): CssShade  { return self::shade('emerald', $shade); }
    public static function teal(int $shade = 50): CssShade    { return self::shade('teal', $shade); }
    public static function cyan(int $shade = 50): CssShade    { return self::shade('cyan', $shade); }
    public static function sky(int $shade = 50): CssShade     { return self::shade('sky', $shade); }
    public static function blue(int $shade = 50): CssShade    { return self::shade('blue', $shade); }
    public static function indigo(int $shade = 50): CssShade  { return self::shade('indigo', $shade); }
    public static function violet(int $shade = 50): CssShade  { return self::shade('violet', $shade); }
    public static function purple(int $shade = 50): CssShade  { return self::shade('purple', $shade); }
    public static function fuchsia(int $shade = 50): CssShade  { return self::shade('fuchsia', $shade); }
    public static function pink(int $shade = 50): CssShade    { return self::shade('pink', $shade); }
    public static function rose(int $shade = 50): CssShade    { return self::shade('rose', $shade); }

    private static function shade(string $family, int $shade): CssShade
    {
        if (!in_array($shade, self::SHADES, true)) {
            throw new \InvalidArgumentException(
                "invalid shade $shade — expected one of " . implode(', ', self::SHADES),
            );
        }
        [$hue, $sat] = self::FAMILIES[$family];
        $familyIndex = array_search($family, array_keys(self::FAMILIES), true);
        return new CssShade(
            $familyIndex * count(self::SHADES) + intdiv($shade, 10),
            "hsl($hue, $sat%, $shade%)",
        );
    }

    // ---- non-palette hex --------------------------------------------------

    /**
     * A non-named hex colour, given as a 24-bit integer literal 0xRRGGBB —
     * e.g. hex(0xFF0000). Encodes as the codec's COLOR hex subtype.
     */
    public static function hex(int $hex): CssHexColor
    {
        return new CssHexColor($hex);
    }
}

/**
 * A resolved design-system colour: a family + shade, produced by the
 * {@see CssColor} family factories. Encodes as COLOR "index".
 */
final class CssShade implements CssColorValue
{
    public function __construct(
        private readonly int $index,
        private readonly string $css,
    ) {}

    public function index(): int
    {
        return $this->index;
    }

    public function toCss(): string
    {
        return $this->css;
    }
}

/**
 * A literal RGB hex colour, held as a 24-bit integer (0xRRGGBB). Encodes as the
 * COLOR hex subtype; renders as '#rrggbb'.
 */
final class CssHexColor implements CssDeclarationValue
{
    public function __construct(public readonly int $rgb)
    {
        if ($rgb < 0 || $rgb > 0xFFFFFF) {
            throw new \InvalidArgumentException(sprintf('hex colour out of 24-bit range: 0x%X', $rgb));
        }
    }

    public function red(): int
    {
        return ($this->rgb >> 16) & 0xFF;
    }

    public function green(): int
    {
        return ($this->rgb >> 8) & 0xFF;
    }

    public function blue(): int
    {
        return $this->rgb & 0xFF;
    }

    public function toCss(): string
    {
        return sprintf('#%06x', $this->rgb);
    }
}
