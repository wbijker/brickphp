<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * One atomic utility class: a single `property: value` declaration plus optional
 * variant context (media, supports, combinators, pseudo-classes, a pseudo-
 * element). The class's NAME is a SELF-DESCRIBING token chain — the name alone
 * carries everything needed to rebuild the CSS rule, so a JS decoder with the
 * same static tables ({@see CssProperty::ALIASES}, {@see CssMedia}, …) can
 * reconstruct it with no build step and no side dictionary.
 *
 * NAME GRAMMAR
 *   [variant _]…  alias-[value]
 *
 *   · `_` separates variant tokens and delimits the trailing declaration.
 *   · the declaration is `alias-[value]`: an {@see CssProperty::alias()} code,
 *     then the LITERAL CSS value in brackets (spaces written as `_`).
 *   · every earlier `_`-segment is a variant token, resolved (in order) against
 *     {@see CssMedia}, {@see CssSupports}, {@see CssCombinator}, the pseudo-
 *     element and pseudo-class tables.
 *
 * Examples
 *   display:flex                                   → d-[flex]
 *   padding:12px                                   → p-[12px]
 *   :hover color:#3b82f6                           → hover_c-[#3b82f6]
 *   @media md + :hover  background-color:#fff      → md_hover_bgc-[#fff]
 *   ::before content:""                            → before_ct-[""]
 *   > *  margin-top:1rem                           → child_mt-[1rem]
 *
 * Names use the full CSS-value charset (`[`, `#`, `.`, `%`, `(`…); the emitted
 * SELECTOR back-slash-escapes those, exactly as Tailwind does. A JS decoder
 * reads the raw (unescaped) `className`, so escaping never concerns it.
 */
final class CssClass
{
    private CssProperty $property;
    private CssDeclarationValue $value;

    /** @var list<CssMedia> */
    private array $media = [];
    /** @var list<CssSupports> */
    private array $supports = [];
    /** @var list<CssCombinator> */
    private array $combinators = [];
    /** @var list<CssPseudoClass> */
    private array $pseudoClasses = [];
    private ?CssPseudoElement $pseudoElement = null;

    // ---- reverse lookups (built once) -------------------------------------
    /** @var array<string,CssPseudoClass>|null token → case */
    private static ?array $pseudoClassTokens = null;
    /** @var array<string,CssPseudoElement>|null token → case */
    private static ?array $pseudoElementTokens = null;

    // ---- construction -----------------------------------------------------

    public function __construct(CssProperty $property, CssDeclarationValue $value)
    {
        $this->property = $property;
        $this->value = $value;
    }

    /** An atomic declaration class. */
    public static function of(CssProperty $property, CssDeclarationValue $value): self
    {
        return new self($property, $value);
    }

    /** Wrap in one or more `@media` variants. */
    public function media(CssMedia ...$media): static
    {
        array_push($this->media, ...$media);
        return $this;
    }

    /** Wrap in one or more `@supports` variants. */
    public function supports(CssSupports ...$supports): static
    {
        array_push($this->supports, ...$supports);
        return $this;
    }

    /** Retarget through one or more combinators (child, group-hover, …). */
    public function combinator(CssCombinator ...$combinators): static
    {
        array_push($this->combinators, ...$combinators);
        return $this;
    }

    /** Add one or more pseudo-classes (source order preserved). */
    public function pseudo(CssPseudoClass ...$pseudoClasses): static
    {
        array_push($this->pseudoClasses, ...$pseudoClasses);
        return $this;
    }

    /** Target a pseudo-element (::before, ::after, …). */
    public function element(CssPseudoElement $pseudoElement): static
    {
        $this->pseudoElement = $pseudoElement;
        return $this;
    }

    // ---- encoding ---------------------------------------------------------

    /** The self-describing class name (see grammar above). */
    public function name(): string
    {
        $tokens = [];
        foreach ($this->sortedMedia() as $m) {
            $tokens[] = $m->value;
        }
        foreach ($this->supports as $s) {
            $tokens[] = $s->value;
        }
        foreach ($this->combinators as $c) {
            $tokens[] = $c->value;
        }
        foreach ($this->pseudoClasses as $pc) {
            $tokens[] = self::pseudoClassToken($pc);
        }
        if ($this->pseudoElement !== null) {
            $tokens[] = self::pseudoElementToken($this->pseudoElement);
        }
        $tokens[] = $this->property->alias() . '-[' . self::encodeValue($this->value->toCss()) . ']';
        return implode('_', $tokens);
    }

    /** The full CSS rule this class expands to (its name is the selector). */
    public function toCss(): string
    {
        return self::cssFor($this->name());
    }

    public function __toString(): string
    {
        return $this->name();
    }

    /** Breakpoints before feature queries, so `dark:lg` == `lg:dark`. */
    private function sortedMedia(): array
    {
        $media = $this->media;
        usort($media, static fn(CssMedia $a, CssMedia $b): int => $a->rank() <=> $b->rank());
        return $media;
    }

    // ---- decoding (the JS mirror) -----------------------------------------

    /**
     * Parse a class name back into its parts. Returns:
     *   media[], supports[], combinators[], pseudoClasses[], pseudoElement,
     *   property (CssProperty|null), propertyName (string), value (string).
     *
     * @return array{
     *     media: list<CssMedia>, supports: list<CssSupports>,
     *     combinators: list<CssCombinator>, pseudoClasses: list<CssPseudoClass>,
     *     pseudoElement: ?CssPseudoElement, property: ?CssProperty,
     *     propertyName: string, value: string,
     * }
     */
    public static function decode(string $name): array
    {
        $open = strpos($name, '[');
        $close = strrpos($name, ']');
        if ($open === false || $close === false || $close < $open) {
            throw new \InvalidArgumentException("not a CssClass name (no bracketed value): $name");
        }
        $value = self::decodeValue(substr($name, $open + 1, $close - $open - 1));

        $head = rtrim(substr($name, 0, $open), '-');   // "…_alias"
        $segments = $head === '' ? [] : explode('_', $head);
        $alias = array_pop($segments);
        $property = $alias !== null ? CssProperty::fromAlias($alias) : null;

        $out = [
            'media' => [], 'supports' => [], 'combinators' => [],
            'pseudoClasses' => [], 'pseudoElement' => null,
            'property' => $property,
            'propertyName' => $property?->css() ?? (string) $alias,
            'value' => $value,
        ];

        foreach ($segments as $token) {
            if (($m = CssMedia::tryFrom($token)) !== null) {
                $out['media'][] = $m;
            } elseif (($s = CssSupports::tryFrom($token)) !== null) {
                $out['supports'][] = $s;
            } elseif (($c = CssCombinator::tryFrom($token)) !== null) {
                $out['combinators'][] = $c;
            } elseif (($e = self::pseudoElementFromToken($token)) !== null) {
                $out['pseudoElement'] = $e;
            } elseif (($p = self::pseudoClassFromToken($token)) !== null) {
                $out['pseudoClasses'][] = $p;
            } else {
                throw new \InvalidArgumentException("unknown variant token '$token' in: $name");
            }
        }
        return $out;
    }

    /** Reconstruct the full CSS rule for a class name (mirrors the JS decoder). */
    public static function cssFor(string $name): string
    {
        $parts = self::decode($name);

        $sel = '.' . self::cssEscape($name);
        foreach ($parts['combinators'] as $c) {
            $sel = $c->wrap($sel);
        }
        foreach ($parts['pseudoClasses'] as $pc) {
            $sel .= $pc->css();
        }
        if ($parts['pseudoElement'] !== null) {
            $sel .= $parts['pseudoElement']->css();
        }

        $rule = $sel . '{' . $parts['propertyName'] . ':' . $parts['value'] . '}';

        if ($parts['supports'] !== []) {
            $conds = array_map(static fn(CssSupports $s): string => $s->condition(), $parts['supports']);
            $rule = '@supports ' . implode(' and ', $conds) . '{' . $rule . '}';
        }
        if ($parts['media'] !== []) {
            $conds = array_map(static fn(CssMedia $m): string => $m->condition(), $parts['media']);
            $rule = '@media ' . implode(' and ', $conds) . '{' . $rule . '}';
        }
        return $rule;
    }

    // ---- value + token helpers --------------------------------------------

    /** Literal CSS value → bracket payload (only spaces need encoding). */
    private static function encodeValue(string $css): string
    {
        return str_replace(' ', '_', $css);
    }

    /** Bracket payload → literal CSS value. */
    private static function decodeValue(string $payload): string
    {
        return str_replace('_', ' ', $payload);
    }

    private static function pseudoClassToken(CssPseudoClass $pc): string
    {
        return substr($pc->css(), 1);          // ':hover' → 'hover'
    }

    private static function pseudoElementToken(CssPseudoElement $pe): string
    {
        return substr($pe->css(), 2);          // '::before' → 'before'
    }

    private static function pseudoClassFromToken(string $token): ?CssPseudoClass
    {
        if (self::$pseudoClassTokens === null) {
            self::$pseudoClassTokens = [];
            foreach (CssPseudoClass::cases() as $pc) {
                self::$pseudoClassTokens[self::pseudoClassToken($pc)] = $pc;
            }
        }
        return self::$pseudoClassTokens[$token] ?? null;
    }

    private static function pseudoElementFromToken(string $token): ?CssPseudoElement
    {
        if (self::$pseudoElementTokens === null) {
            self::$pseudoElementTokens = [];
            foreach (CssPseudoElement::cases() as $pe) {
                self::$pseudoElementTokens[self::pseudoElementToken($pe)] = $pe;
            }
        }
        return self::$pseudoElementTokens[$token] ?? null;
    }

    /**
     * Escape a class name for use in a selector (`.name`) — CSSOM-style: word
     * chars pass through, a leading digit gets a hex escape, everything else is
     * back-slash-escaped.
     */
    private static function cssEscape(string $name): string
    {
        $out = '';
        foreach (str_split($name) as $i => $ch) {
            if (ctype_alpha($ch) || $ch === '_' || ord($ch) >= 0x80) {
                $out .= $ch;
            } elseif (ctype_digit($ch)) {
                $out .= $i === 0 ? '\\3' . $ch . ' ' : $ch;
            } elseif ($ch === '-') {
                $out .= $i === 0 ? '\\-' : '-';
            } else {
                $out .= '\\' . $ch;
            }
        }
        return $out;
    }
}
