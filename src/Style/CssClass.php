<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * One atomic CSS class: an ordered set of declarations (property + value) with
 * optional pseudo wrappers, encoded to the CSS-PACKER wire format. The class's
 * NAME is those packed bytes, base64-encoded — so the name round-trips back to
 * the declarations via {@see StyleDefinitions} + the type enums.
 *
 * Wire format (see codec spec). The top declaration byte has four forms, one
 * class each (all extend {@see Declaration}, each owning its own prefix +
 * `encode()`):
 *   0xxx xxxx  {@see Pair}   — whole property:value from PAIRS_128       (1 byte)
 *   10xx xxxx  {@see Prop64} — property from PROPS_64, value bytes follow
 *   110x xxxx  {@see Pseudo} — pseudo-class wrapper (prefixes a declaration)
 *   1110 xxxx  {@see Ext}    — full property index+value · or pseudo-element
 *
 * The value bytes are a {@see Value}: a `tttt yyyy` lead byte (4 type bits + 4
 * payload bits) plus any trailing bytes, one subclass per VALUE_TYPE (Static32,
 * StaticOther, Num1/Num2/NumScale, StrS/StrL, FracI/Frac, Color), each owning
 * its own layout. Bytes are assembled with {@see pack()}.
 */
final class CssClass
{
    // NUM unit-code escape (payload 7 → full CssUnit index in the next byte).
    private const UNIT_ESCAPE = 7;

    /** @var list<array{property: CssProperty, value: CssDeclarationValue}> */
    private array $declarations = [];

    /** @var list<CssPseudoClass|CssPseudoElement> pseudo wrappers, applied to all declarations */
    private array $wrappers = [];

    // ---- derived lookups (built once) -------------------------------------
    /** @var array<string,int>|null "propIdx:valueIdx" → PAIRS_128 slot */
    private static ?array $pairIndex = null;
    /** @var array<int,int>|null PROPS_ALL index → PROPS_64 slot */
    private static ?array $prop64Index = null;
    /** @var array<int,int>|null VALUES_ALL index → VALUES_80 position (0..79) */
    private static ?array $valueStaticIndex = null;
    /** @var array<string,int>|null value string → VALUES_ALL index */
    private static ?array $valueAllIndex = null;
    /** @var array<string,int>|null unit token → 3-bit UNITS7 code */
    private static ?array $unit7Index = null;
    /** @var array<string,int>|null unit token → full CssUnit index */
    private static ?array $unitIndex = null;
    /** @var array<string,int>|null NUM_SCALE16 value (as string) → index */
    private static ?array $numScaleIndex = null;
    /** @var array<string,int>|null fraction string → FRACTIONS_16 index */
    private static ?array $fractionIndex = null;

    // ---- construction -----------------------------------------------------

    /** Atomic single-declaration class. */
    public static function of(CssProperty $property, CssDeclarationValue $value): self
    {
        return (new self())->add($property, $value);
    }

    /** Add a declaration. */
    public function add(CssProperty $property, CssDeclarationValue $value): static
    {
        $this->declarations[] = ['property' => $property, 'value' => $value];
        return $this;
    }

    /** Attach one or more pseudo wrappers (applied to every declaration). */
    public function pseudo(CssPseudoClass|CssPseudoElement ...$wrappers): static
    {
        array_push($this->wrappers, ...$wrappers);
        return $this;
    }

    /** @return list<array{property: CssProperty, value: CssDeclarationValue}> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    // ---- encoding ---------------------------------------------------------

    /** Packed wire bytes for this class (binary string, via pack()). */
    public function encode(): string
    {
        $out = '';
        foreach ($this->declarations as $d) {
            foreach ($this->wrappers as $w) {
                $out .= $this->wrapper($w)->encode();
            }
            $out .= $this->declaration($d['property'], $d['value'])->encode();
        }
        return $out;
    }

    /** The class name: packed bytes, base64-encoded. */
    public function name(): string
    {
        return base64_encode($this->encode());
    }

    public function __toString(): string
    {
        return $this->name();
    }

    // ---- form selection (each form encodes itself; see the classes below) --

    /** Pick the wrapper form for a pseudo. */
    private function wrapper(CssPseudoClass|CssPseudoElement $w): Declaration
    {
        return $w instanceof CssPseudoElement
            ? new Ext(Ext::PSEUDO_ELEM, $w->value)   // 1110 0010 + index
            : new Pseudo($w->value);                 // 110x xxxx
    }

    /** Pick the cheapest declaration form for one property:value. */
    private function declaration(CssProperty $property, CssDeclarationValue $value): Declaration
    {
        $staticIndex = $this->staticIndex($value);

        // 1. Whole property:value pair → single 0xxx xxxx byte.
        if ($staticIndex !== null) {
            $slot = self::pairIndex()[$property->value . ':' . $staticIndex] ?? null;
            if ($slot !== null) {
                return new Pair($slot);
            }
        }

        $value = $this->valueFor($value, $staticIndex);

        // 2. Property among the 64 hottest → 10xx xxxx + value.
        $slot64 = self::prop64Index()[$property->value] ?? null;
        if ($slot64 !== null) {
            return new Prop64($slot64, $value);
        }

        // 3. Extended: 1110 0000 + full property index + value.
        return new Ext(Ext::PROP, $property->value & 0xff, $value);
    }

    // ---- value bytes ------------------------------------------------------

    /**
     * VALUES_ALL index for a value that resolves to a known static value —
     * either a {@see CssStaticValue} case or a literal whose CSS string is one
     * of the static values (e.g. "1", "0", "#fff", "0 auto"). null otherwise.
     * Checked before number/colour/string classification, mirroring the JS
     * codec — so hot property:value combos still collapse to a 1-byte PAIR even
     * when the value was written as a literal.
     */
    private function staticIndex(CssDeclarationValue $value): ?int
    {
        if ($value instanceof CssStaticValue) {
            return $value->value;
        }
        if ($value instanceof CssColorValue) {
            return null;                           // palette colours use the COLOR index subtype
        }
        return self::valueAllIndex()[$value->toCss()] ?? null;
    }

    /** The value byte-form for a declaration value (each {@see Value} encodes itself). */
    private function valueFor(CssDeclarationValue $value, ?int $staticIndex): Value
    {
        if ($staticIndex !== null) {
            return $this->staticValue($staticIndex);
        }
        if ($value instanceof CssColorValue) {
            return new ColorIndex($value->index());
        }
        if ($value instanceof CssHexColor) {
            return new ColorHex($value->red(), $value->green(), $value->blue());
        }
        // CssLiteral or any other: classify its CSS string.
        return $this->literalValue($value->toCss());
    }

    /**
     * A known static value (index into CssStaticValue::ALL): the cheapest of the
     * five 1-byte STATIC_32 banks or the 2-byte STATIC_OTHER form.
     */
    private function staticValue(int $valueIndex): Value
    {
        $pos = self::valueStaticIndex()[$valueIndex] ?? null;
        if ($pos !== null) {                       // 0..79 → bank (type nibble) + slot (payload)
            return new Static32($pos >> 4, $pos & 0x0f);
        }
        return new StaticOther($valueIndex);
    }

    /**
     * Classify a raw CSS value string into the cheapest value type, mirroring the
     * JS codec's encodeValue priority: fraction → number+unit → colour → string.
     */
    private function literalValue(string $value): Value
    {
        // FRAC_I / FRAC — "n/d".
        if (preg_match('#^(\d+)\s*/\s*(\d+)$#', $value, $m)) {
            $fi = self::fractionIndex()[$value] ?? null;
            if ($fi !== null) {
                return new FracI($fi);
            }
            $num = (int) $m[1];
            $den = (int) $m[2];
            if ($num <= 15 && $den <= 255) {
                return new Frac($num, $den);
            }
        }

        // NUM_1 / NUM_SCALE / NUM_2 — non-negative number + optional unit.
        if (preg_match('/^(\d*\.?\d+)([a-z%]*)$/i', $value, $m)) {
            $n = (float) $m[1];
            $unit = $this->unitPrefix(strtolower($m[2]));
            if ($unit !== null && $n >= 0) {
                [$code, $esc] = $unit;
                if ($this->isInt($n) && $n <= 0xff) {
                    return new Num1($code, $esc, (int) $n);
                }
                $scale = self::numScaleIndex()[self::numKey($n)] ?? null;
                if ($scale !== null) {
                    return new NumScale($code, $esc, $scale);
                }
                if ($this->isInt($n) && $n <= 0xffff) {
                    return new Num2($code, $esc, (int) $n);
                }
            }
        }

        // COLOR — hex / rgb / rgba.
        $color = $this->colorValue($value);
        if ($color !== null) {
            return $color;
        }

        // STR_S / STR_L — raw UTF-8 fallback (calc(), odd decimals, unknown keywords…).
        $len = strlen($value);
        if ($len < 16) {
            return new StrS($value);
        }
        if ($len < 4096) {
            return new StrL($value);
        }
        throw new \RangeError("value too long to encode ($len bytes): $value");
    }

    /** Lossless colour subtypes only (hex lowercased); null if not a colour. */
    private function colorValue(string $value): ?Color
    {
        if (preg_match('/^#([0-9a-f]{6})$/i', $value, $m)) {
            $h = strtolower($m[1]);
            return new ColorHex(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
        }
        if (preg_match('/^#([0-9a-f]{8})$/i', $value, $m)) {
            $h = strtolower($m[1]);
            return new ColorHexa(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)),
                hexdec(substr($h, 4, 2)), hexdec(substr($h, 6, 2)));
        }
        if (preg_match('/^rgb\((\d{1,3}),\s?(\d{1,3}),\s?(\d{1,3})\)$/i', $value, $m)) {
            [$r, $g, $b] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($r <= 255 && $g <= 255 && $b <= 255) {
                return new ColorRgb($r, $g, $b);
            }
        }
        if (preg_match('/^rgba\((\d{1,3}),\s?(\d{1,3}),\s?(\d{1,3}),\s?(0|1|0?\.\d+)\)$/i', $value, $m)) {
            [$r, $g, $b] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($r <= 255 && $g <= 255 && $b <= 255) {
                return new ColorRgba($r, $g, $b, (int) round(((float) $m[4]) * 255));
            }
        }
        return null;
    }

    /** unitPrefix → [payloadCode, escapeBytes[]] or null for an unknown unit. */
    private function unitPrefix(string $unit): ?array
    {
        $code = self::unit7Index()[$unit] ?? null;
        if ($code !== null) {
            return [$code, []];                              // common unit: 3-bit code
        }
        $full = self::unitIndex()[$unit] ?? null;
        if ($full !== null) {
            return [self::UNIT_ESCAPE, [$full]];             // escape + full unit byte
        }
        return null;
    }

    private function isInt(float $n): bool
    {
        return $n === floor($n);
    }

    private static function numKey(float $n): string
    {
        return rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
    }

    // ---- lookup builders --------------------------------------------------

    /** @return array<string,int> */
    private static function pairIndex(): array
    {
        if (self::$pairIndex === null) {
            self::$pairIndex = [];
            foreach (StyleDefinitions::PAIRS_128 as $slot => [$pi, $vi]) {
                self::$pairIndex["$pi:$vi"] = $slot;
            }
        }
        return self::$pairIndex;
    }

    /** @return array<int,int> */
    private static function prop64Index(): array
    {
        return self::$prop64Index ??= array_flip(StyleDefinitions::PROPS_64);
    }

    /** @return array<int,int> */
    private static function valueStaticIndex(): array
    {
        return self::$valueStaticIndex ??= array_flip(StyleDefinitions::VALUES_80);
    }

    /** @return array<string,int> */
    private static function valueAllIndex(): array
    {
        return self::$valueAllIndex ??= array_flip(CssStaticValue::ALL);
    }

    /** @return array<string,int> */
    private static function unit7Index(): array
    {
        if (self::$unit7Index === null) {
            self::$unit7Index = [];
            foreach (StyleDefinitions::UNITS7 as $code => $unitIdx) {
                self::$unit7Index[CssUnit::ALL[$unitIdx]] = $code;
            }
        }
        return self::$unit7Index;
    }

    /** @return array<string,int> */
    private static function unitIndex(): array
    {
        return self::$unitIndex ??= array_flip(CssUnit::ALL);
    }

    /** @return array<string,int> */
    private static function numScaleIndex(): array
    {
        if (self::$numScaleIndex === null) {
            self::$numScaleIndex = [];
            foreach (StyleDefinitions::NUM_SCALE16 as $i => $v) {
                self::$numScaleIndex[self::numKey((float) $v)] = $i;
            }
        }
        return self::$numScaleIndex;
    }

    /** @return array<string,int> */
    private static function fractionIndex(): array
    {
        return self::$fractionIndex ??= array_flip(StyleDefinitions::FRACTIONS_16);
    }
}

/**
 * One top declaration/wrapper byte-form. Each subclass owns its leading-byte
 * PREFIX and knows how to lay out its own payload — {@see CssClass::declaration()}
 * and {@see CssClass::wrapper()} pick a form and call {@see encode()} on it
 * without caring which one it is (Liskov substitution).
 */
abstract class Declaration
{
    /** Packed wire bytes for this form (via pack()). */
    abstract public function encode(): string;
}

/** 0xxx xxxx — a whole property:value pair from PAIRS_128 (one byte). */
final class Pair extends Declaration
{
    private const PREFIX = 0x00;

    public function __construct(private readonly int $slot) {}

    public function encode(): string
    {
        return pack('C', self::PREFIX | $this->slot);
    }
}

/** 10xx xxxx — a PROPS_64 property, followed by the value bytes. */
final class Prop64 extends Declaration
{
    private const PREFIX = 0x80;

    public function __construct(private readonly int $slot, private readonly Value $value) {}

    public function encode(): string
    {
        return pack('C', self::PREFIX | $this->slot) . $this->value->encode();
    }
}

/** 110x xxxx — a pseudo-class wrapper that prefixes a declaration. */
final class Pseudo extends Declaration
{
    private const PREFIX = 0xC0;

    public function __construct(private readonly int $index) {}

    public function encode(): string
    {
        return pack('C', self::PREFIX | $this->index);
    }
}

/**
 * 1110 xxxx — the extended marker. The op nibble selects the payload:
 *   PROP        → full property index byte + value bytes (a declaration)
 *   PSEUDO_ELEM → pseudo-element index byte (a wrapper, no value)
 */
final class Ext extends Declaration
{
    private const PREFIX = 0xE0;
    public const PROP = 0x0, PSEUDO_ELEM = 0x2;

    public function __construct(
        private readonly int $op,
        private readonly int $index,
        private readonly ?Value $value = null,
    ) {}

    public function encode(): string
    {
        return pack('C', self::PREFIX | $this->op) . pack('C', $this->index) . ($this->value?->encode() ?? '');
    }
}

/**
 * One CSS value byte-form (a VALUE_TYPE). The lead byte is `tttt yyyy` — the
 * TYPE nibble plus a payload nibble — optionally followed by more bytes. Each
 * subclass owns its own layout and encodes itself; {@see CssClass::valueFor()}
 * picks one and the declaration forms call {@see encode()} polymorphically.
 */
abstract class Value
{
    /** Packed value bytes (via pack()). */
    abstract public function encode(): string;
}

/**
 * Types 0-4 — STATIC_32_1..5. One of the five 16-slot static banks (VALUES_80):
 * the bank IS the type nibble, the slot is the payload. One byte.
 */
final class Static32 extends Value
{
    public function __construct(private readonly int $bank, private readonly int $slot) {}

    public function encode(): string
    {
        return pack('C', ($this->bank << 4) | $this->slot);
    }
}

/** Type 5 — STATIC_OTHER. A 12-bit index (payload nibble + next byte → 4096). */
final class StaticOther extends Value
{
    private const TYPE = 5;

    public function __construct(private readonly int $index) {}

    public function encode(): string
    {
        return pack('CC', (self::TYPE << 4) | ($this->index >> 8), $this->index & 0xff);
    }
}

/**
 * A number carrying a unit. The payload nibble is the 3-bit UNITS7 code (or the
 * escape 7, followed by a full CssUnit index byte); the numeric value follows in
 * the subclass's own bytes. Base for NUM_SCALE / NUM_1 / NUM_2.
 */
abstract class Num extends Value
{
    /** @param int[] $unitEscape 0 or 1 trailing unit bytes (present when code == 7) */
    public function __construct(protected readonly int $unitCode, protected readonly array $unitEscape) {}

    protected function head(int $type): string
    {
        $out = pack('C', ($type << 4) | $this->unitCode);
        return $this->unitEscape === [] ? $out : $out . pack('C*', ...$this->unitEscape);
    }
}

/** Type 6 — NUM_SCALE. A design-scale index (NUM_SCALE16, p-1..p-16). */
final class NumScale extends Num
{
    private const TYPE = 6;

    public function __construct(int $unitCode, array $unitEscape, private readonly int $scaleIndex)
    {
        parent::__construct($unitCode, $unitEscape);
    }

    public function encode(): string
    {
        return $this->head(self::TYPE) . pack('C', $this->scaleIndex);
    }
}

/** Type 7 — NUM_1. An integer 0-255 in the following byte. */
final class Num1 extends Num
{
    private const TYPE = 7;

    public function __construct(int $unitCode, array $unitEscape, private readonly int $value)
    {
        parent::__construct($unitCode, $unitEscape);
    }

    public function encode(): string
    {
        return $this->head(self::TYPE) . pack('C', $this->value);
    }
}

/** Type 8 — NUM_2. An integer 0-65535 in the following two bytes (big-endian). */
final class Num2 extends Num
{
    private const TYPE = 8;

    public function __construct(int $unitCode, array $unitEscape, private readonly int $value)
    {
        parent::__construct($unitCode, $unitEscape);
    }

    public function encode(): string
    {
        return $this->head(self::TYPE) . pack('n', $this->value);
    }
}

/** Type 9 — STR_S. A raw string, length < 16 (length in the payload nibble). */
final class StrS extends Value
{
    private const TYPE = 9;

    public function __construct(private readonly string $string) {}

    public function encode(): string
    {
        return pack('C', (self::TYPE << 4) | strlen($this->string)) . $this->string;
    }
}

/** Type 10 — STR_L. A raw string, length < 4096 (payload nibble + next byte). */
final class StrL extends Value
{
    private const TYPE = 10;

    public function __construct(private readonly string $string) {}

    public function encode(): string
    {
        $len = strlen($this->string);
        return pack('CC', (self::TYPE << 4) | ($len >> 8), $len & 0xff) . $this->string;
    }
}

/** Type 11 — FRAC_I. One of the 16 indexed common fractions (FRACTIONS_16). */
final class FracI extends Value
{
    private const TYPE = 11;

    public function __construct(private readonly int $index) {}

    public function encode(): string
    {
        return pack('C', (self::TYPE << 4) | $this->index);
    }
}

/** Type 12 — FRAC. Numerator 0-15 in the payload, denominator 0-255 next byte. */
final class Frac extends Value
{
    private const TYPE = 12;

    public function __construct(private readonly int $numerator, private readonly int $denominator) {}

    public function encode(): string
    {
        return pack('CC', (self::TYPE << 4) | $this->numerator, $this->denominator);
    }
}

/**
 * Type 13 — COLOR. The value type is COLOR; the payload nibble selects a colour
 * SUBTYPE, and channel/index bytes follow. One class per subtype (see below);
 * each defines its {@see subtype()} nibble and the {@see channels()} that follow.
 *
 * Subtypes (spec d):
 *   0 index · 1 hex · 2 hexa · 3 rgb · 4 rgba · 5 hsl · 6 hwb · 7 lab/lch · 8 ext
 */
abstract class Color extends Value
{
    private const TYPE = 13;

    /** The 4-bit colour subtype (payload nibble). */
    abstract protected function subtype(): int;

    /** @return int[] the channel / index bytes that follow the lead byte */
    abstract protected function channels(): array;

    public function encode(): string
    {
        return pack('C*', (self::TYPE << 4) | $this->subtype(), ...$this->channels());
    }
}

/** Subtype 0 — a known colour + shading, one index byte. */
final class ColorIndex extends Color
{
    public function __construct(private readonly int $index) {}
    protected function subtype(): int { return 0; }
    protected function channels(): array { return [$this->index]; }
}

/** Subtype 1 — #rrggbb, three channel bytes. */
final class ColorHex extends Color
{
    public function __construct(private readonly int $r, private readonly int $g, private readonly int $b) {}
    protected function subtype(): int { return 1; }
    protected function channels(): array { return [$this->r, $this->g, $this->b]; }
}

/** Subtype 2 — #rrggbbaa, four channel bytes (alpha 0-255). */
final class ColorHexa extends Color
{
    public function __construct(
        private readonly int $r, private readonly int $g, private readonly int $b, private readonly int $a,
    ) {}
    protected function subtype(): int { return 2; }
    protected function channels(): array { return [$this->r, $this->g, $this->b, $this->a]; }
}

/** Subtype 3 — rgb(), three channel bytes. */
final class ColorRgb extends Color
{
    public function __construct(private readonly int $r, private readonly int $g, private readonly int $b) {}
    protected function subtype(): int { return 3; }
    protected function channels(): array { return [$this->r, $this->g, $this->b]; }
}

/** Subtype 4 — rgba(), four channel bytes (alpha 0-255). */
final class ColorRgba extends Color
{
    public function __construct(
        private readonly int $r, private readonly int $g, private readonly int $b, private readonly int $a,
    ) {}
    protected function subtype(): int { return 4; }
    protected function channels(): array { return [$this->r, $this->g, $this->b, $this->a]; }
}

/** Subtype 5 — hsl(), three channel bytes. */
final class ColorHsl extends Color
{
    public function __construct(private readonly int $h, private readonly int $s, private readonly int $l) {}
    protected function subtype(): int { return 5; }
    protected function channels(): array { return [$this->h, $this->s, $this->l]; }
}

/** Subtype 6 — hwb(), three channel bytes. */
final class ColorHwb extends Color
{
    public function __construct(private readonly int $h, private readonly int $w, private readonly int $b) {}
    protected function subtype(): int { return 6; }
    protected function channels(): array { return [$this->h, $this->w, $this->b]; }
}

/** Subtype 7 — lab() / lch(), three channel bytes. */
final class ColorLab extends Color
{
    public function __construct(private readonly int $l, private readonly int $a, private readonly int $b) {}
    protected function subtype(): int { return 7; }
    protected function channels(): array { return [$this->l, $this->a, $this->b]; }
}

/** Subtype 8 — extended (not yet used). */
final class ColorExt extends Color
{
    protected function subtype(): int { return 8; }
    protected function channels(): array { return []; }
}
