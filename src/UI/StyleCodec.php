<?php

namespace BrickPHP\UI;

/**
 * Binary encoding of utility styles. A style is `(className, declarations)`
 * where className carries the modifier prefix (`lg:hover:…`) and declarations
 * are the resolved CSS `prop => value` pairs.
 *
 * TABLE format (what StyleRegistry ships, base64): a sequence of records in
 * ascending ordinal order. Ordinals are implicit: a record's ordinal is the
 * previous record's + 1, unless the head byte's GAP flag is set, in which case
 * a LEB128 varint gap (>= 2) follows the head byte. The first record's
 * "previous ordinal" is -1, so a table starting at ordinal 0 pays no ordinal
 * bytes at all.
 *
 * RECORD format:
 *
 *   [head:1] [gap varint]? [modifier]? [decl]*
 *
 *   head: bits 0-4 declCount (1-31), bit 5 MOD (packed modifier byte follows),
 *         bit 6 EXOTIC (length-prefixed prefix string follows), bit 7 GAP.
 *         MOD and EXOTIC are mutually exclusive; neither = no prefix.
 *
 *   modifier (MOD): one byte — breakpoint (bits 0-2, {@see BREAKPOINTS} order),
 *         color scheme (bits 3-4), pseudo (bits 5-7). Pseudo 1-6 index
 *         {@see PACKED_PSEUDO}; 7 means "extended": [count:1][pseudoId:1]* from
 *         {@see PSEUDO_LIST} follows, order preserved (pseudo order can be
 *         semantically meaningful). EXOTIC covers anything else (has-[…] etc.).
 *
 *   decl: [0xFE][declId]                      — whole declaration from DECLS
 *       | [propId][valType][valueBytes]       — property from PROPS
 *       | [0xFF][nameLen][name][valType][val] — property not in PROPS
 *
 * All dictionaries (PROPS, KEYWORDS, DECLS, PSEUDO_LIST) are APPEND-ONLY wire
 * format, mirrored byte-for-byte in brick.js — never reorder or remove
 * entries. Anything outside a dictionary still encodes (name inline / RAW
 * value), so dictionaries only affect size, never correctness.
 */
final class StyleCodec
{
    // Value-type tags.
    public const PX16 = 1, PCT = 2, VH = 3, VW = 4, REM = 5, EM = 6,
        RGB = 7, RGBA = 8, DECIMAL = 9, RAW = 10, RAW16 = 11,
        PX8 = 12, INT16 = 13, KEYWORD = 14;

    // Head-byte flags.
    public const DECLS_MASK = 31, F_MOD = 32, F_EXOTIC = 64, F_GAP = 128;

    // Decl escapes.
    public const PROP_CUSTOM = 0xFF, DECL_DICT = 0xFE;

    /** CSS property dictionary — index IS the wire id. APPEND-ONLY. */
    public const PROPS = [
        'display', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink',
        'align-items', 'justify-content', 'gap',
        'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'background-color', 'color', 'opacity',
        'border', 'border-width', 'border-style', 'border-color', 'border-radius',
        'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
        'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
        'font-size', 'font-weight', 'line-height', 'text-align', 'text-transform',
        'box-shadow', 'cursor', 'overflow', 'overflow-x', 'overflow-y',
        'position', 'top', 'right', 'bottom', 'left', 'z-index',
        'object-fit', 'transform',
        'transition-property', 'transition-duration', 'transition-timing-function',
        'grid-template-columns', 'grid-auto-flow', 'white-space',
    ];

    /** CSS keyword-value dictionary — index IS the wire id. APPEND-ONLY. */
    public const KEYWORDS = [
        'flex', 'column', 'row', 'hidden', 'auto', 'center', 'pointer', 'none',
        'wrap', 'solid', 'uppercase', 'relative', 'absolute', 'transparent',
        'space-between', 'all', 'contain', 'cover', 'wrap-reverse', 'baseline',
        'stretch', 'flex-start', 'flex-end', 'ease', 'ease-in', 'ease-out',
        'ease-in-out', 'block', 'inline-block', 'grid', 'inline-flex',
        'underline', 'line-through', 'nowrap', 'scroll', 'visible', 'collapse',
        'fit-content', 'min-content', 'max-content', 'bold', 'normal', 'italic',
    ];

    /** Whole-declaration dictionary (the hottest pairs). APPEND-ONLY. */
    public const DECLS = [
        'display:flex', 'flex-direction:column', 'flex-direction:row',
        'overflow:hidden', 'cursor:pointer', 'position:relative',
        'position:absolute', 'width:100%', 'height:100%', 'align-items:center',
        'justify-content:center', 'justify-content:space-between',
        'flex-wrap:wrap', 'flex-grow:1', 'flex-shrink:0', 'border:none',
        'text-align:center', 'text-transform:uppercase',
        'background-color:transparent', 'object-fit:contain', 'border-style:solid',
    ];

    /**
     * Full pseudo dictionary for the extended modifier form — index IS the
     * wire id. APPEND-ONLY. Token order mirrors StyleGenerator::PSEUDOS.
     */
    public const PSEUDO_LIST = [
        'hover', 'active', 'focus', 'focus-visible', 'focus-within', 'visited',
        'disabled', 'enabled', 'checked', 'required', 'valid', 'invalid',
        'placeholder', 'first', 'last', 'only', 'odd', 'even', 'empty',
    ];

    /** Pseudo token → CSS selector text (mirror of StyleGenerator::PSEUDOS). */
    private const PSEUDO_CSS = [
        'hover' => ':hover', 'active' => ':active', 'focus' => ':focus',
        'focus-visible' => ':focus-visible', 'focus-within' => ':focus-within',
        'visited' => ':visited', 'disabled' => ':disabled', 'enabled' => ':enabled',
        'checked' => ':checked', 'required' => ':required', 'valid' => ':valid',
        'invalid' => ':invalid', 'placeholder' => '::placeholder',
        'first' => ':first-child', 'last' => ':last-child', 'only' => ':only-child',
        'odd' => ':nth-child(odd)', 'even' => ':nth-child(even)', 'empty' => ':empty',
    ];

    /** Single-pseudo values for the packed modifier field (1-6; 7 = extended). */
    private const PACKED_PSEUDO = [
        'hover' => 1, 'focus' => 2, 'active' => 3,
        'focus-visible' => 4, 'disabled' => 5, 'checked' => 6,
    ];

    private const BREAKPOINTS = ['sm' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4, '2xl' => 5];
    private const SCHEMES = ['dark' => 1, 'light' => 2];

    private const BP_QUERY = [1 => '(min-width: 640px)', 2 => '(min-width: 768px)', 3 => '(min-width: 1024px)', 4 => '(min-width: 1280px)', 5 => '(min-width: 1536px)'];
    private const SC_QUERY = [1 => '(prefers-color-scheme: dark)', 2 => '(prefers-color-scheme: light)'];

    /** @var array<string,int>|null */
    private static ?array $propIds = null;
    /** @var array<string,int>|null */
    private static ?array $kwIds = null;
    /** @var array<string,int>|null */
    private static ?array $declIds = null;
    /** @var array<string,int>|null */
    private static ?array $pseudoIds = null;

    // ============================================================
    // Encode
    // ============================================================

    /**
     * Encode one style to a record (head byte first, GAP flag clear — the
     * table builder ORs it in and inserts the varint when needed).
     *
     * @param array<string,string> $decls resolved CSS declarations
     */
    public static function encodeRecord(string $className, array $decls): string
    {
        self::$propIds ??= array_flip(self::PROPS);
        self::$kwIds ??= array_flip(self::KEYWORDS);
        self::$declIds ??= array_flip(self::DECLS);

        $prefix = self::prefixOf($className);
        $mod = self::encodePrefix($prefix);

        $body = '';
        foreach ($decls as $prop => $value) {
            $value = trim((string) $value);
            $whole = $prop . ':' . $value;
            if (isset(self::$declIds[$whole])) {
                $body .= chr(self::DECL_DICT) . chr(self::$declIds[$whole]);
                continue;
            }
            $id = self::$propIds[$prop] ?? self::PROP_CUSTOM;
            $body .= chr($id);
            if ($id === self::PROP_CUSTOM) {
                $body .= chr(strlen($prop)) . $prop;
            }
            $body .= self::encodeValue($value);
        }

        $head = count($decls) & self::DECLS_MASK;
        if ($mod !== null) {
            $head |= self::F_MOD;
            $modBytes = $mod;
        } elseif ($prefix !== '') {
            $head |= self::F_EXOTIC;
            $modBytes = chr(strlen($prefix)) . $prefix;
        } else {
            $modBytes = '';
        }

        return chr($head) . $modBytes . $body;
    }

    /**
     * Pack a modifier prefix into bytes, or null when it needs the exotic
     * string escape (has-[…]/nth-[…]/not-[…], unknown tokens, repeats).
     */
    private static function encodePrefix(string $prefix): ?string
    {
        if ($prefix === '') return null; // no prefix — caller emits nothing

        self::$pseudoIds ??= array_flip(self::PSEUDO_LIST);
        $bp = 0;
        $sc = 0;
        $pseudos = [];
        foreach (explode(':', $prefix) as $part) {
            if (isset(self::BREAKPOINTS[$part])) {
                if ($bp !== 0) return null;
                $bp = self::BREAKPOINTS[$part];
            } elseif (isset(self::SCHEMES[$part])) {
                if ($sc !== 0) return null;
                $sc = self::SCHEMES[$part];
            } elseif (isset(self::$pseudoIds[$part])) {
                $pseudos[] = $part;
            } else {
                return null; // exotic
            }
        }

        if ($pseudos === []) {
            return chr($bp | ($sc << 3));
        }
        if (count($pseudos) === 1 && isset(self::PACKED_PSEUDO[$pseudos[0]])) {
            return chr($bp | ($sc << 3) | (self::PACKED_PSEUDO[$pseudos[0]] << 5));
        }
        // Extended: pseudo field 7, then count + ids (source order preserved).
        $out = chr($bp | ($sc << 3) | (7 << 5)) . chr(count($pseudos));
        foreach ($pseudos as $p) {
            $out .= chr(self::$pseudoIds[$p]);
        }
        return $out;
    }

    private static function encodeValue(string $v): string
    {
        $b = fn(int ...$xs) => implode('', array_map('chr', $xs));

        if (preg_match('/^#([0-9a-fA-F]{6})$/', $v, $m)) {
            return $b(self::RGB, hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2)));
        }
        if (preg_match('/^#([0-9a-fA-F]{3})$/', $v, $m)) {
            $h = $m[1];
            return $b(self::RGB, hexdec($h[0] . $h[0]), hexdec($h[1] . $h[1]), hexdec($h[2] . $h[2]));
        }
        if (preg_match('/^(\d+)px$/', $v, $m) && (int) $m[1] < 65536) {
            $n = (int) $m[1];
            return $n < 256 ? $b(self::PX8, $n) : $b(self::PX16, $n >> 8, $n & 255);
        }
        if (preg_match('/^(\d+)%$/', $v, $m) && (int) $m[1] < 256)  return $b(self::PCT, (int) $m[1]);
        if (preg_match('/^(\d+)vh$/', $v, $m) && (int) $m[1] < 256) return $b(self::VH, (int) $m[1]);
        if (preg_match('/^(\d+)vw$/', $v, $m) && (int) $m[1] < 256) return $b(self::VW, (int) $m[1]);
        if (preg_match('/^([\d.]+)(rem|em)$/', $v, $m)) {
            $n = (float) $m[1] * 16;
            if ($n == floor($n) && $n < 256) return $b($m[2] === 'rem' ? self::REM : self::EM, (int) $n);
        }
        if (preg_match('/^-?[\d.]+$/', $v)) {
            $n = round((float) $v * 100);
            if ($n == floor($n) && $n >= 0 && $n < 256) return $b(self::DECIMAL, (int) $n);
            $i = (int) $v;
            if ($i == $v && $i >= 0 && $i < 65536) return $b(self::INT16, $i >> 8, $i & 255);
        }
        if (isset(self::$kwIds[$v])) return $b(self::KEYWORD, self::$kwIds[$v]);
        // Verbatim fallback (lossless).
        $len = strlen($v);
        return $len < 256
            ? chr(self::RAW) . chr($len) . $v
            : chr(self::RAW16) . chr($len >> 8) . chr($len & 255) . $v;
    }

    // ============================================================
    // Decode (PHP reference — the browser mirror lives in brick.js)
    // ============================================================

    /**
     * Decode a whole table blob.
     *
     * @return array<int, array{media: ?string, pseudo: string, decls: array<string,string>}>
     *         keyed by ordinal
     */
    public static function decodeTable(string $blob): array
    {
        $out = [];
        $len = strlen($blob);
        $i = 0;
        $prev = -1;
        while ($i < $len) {
            $head = ord($blob[$i++]);
            $n = $head & self::DECLS_MASK;

            $gap = 1;
            if ($head & self::F_GAP) {
                $gap = 0;
                $shift = 0;
                do {
                    $byte = ord($blob[$i++]);
                    $gap |= ($byte & 127) << $shift;
                    $shift += 7;
                } while ($byte & 128);
            }
            $ord = $prev + $gap;
            $prev = $ord;

            $media = null;
            $pseudo = '';
            if ($head & self::F_MOD) {
                $m = ord($blob[$i++]);
                $bp = $m & 7;
                $sc = ($m >> 3) & 3;
                $ps = ($m >> 5) & 7;
                $mq = [];
                if (isset(self::BP_QUERY[$bp])) $mq[] = self::BP_QUERY[$bp];
                if (isset(self::SC_QUERY[$sc])) $mq[] = self::SC_QUERY[$sc];
                if ($mq !== []) $media = '@media ' . implode(' and ', $mq);
                if ($ps === 7) {
                    $count = ord($blob[$i++]);
                    for ($k = 0; $k < $count; $k++) {
                        $pseudo .= self::PSEUDO_CSS[self::PSEUDO_LIST[ord($blob[$i++])]];
                    }
                } elseif ($ps !== 0) {
                    $token = array_search($ps, self::PACKED_PSEUDO, true);
                    $pseudo = self::PSEUDO_CSS[$token];
                }
            } elseif ($head & self::F_EXOTIC) {
                $pl = ord($blob[$i++]);
                $prefix = substr($blob, $i, $pl);
                $i += $pl;
                [$media, $pseudo] = self::selector($prefix);
            }

            $decls = [];
            for ($d = 0; $d < $n; $d++) {
                $pid = ord($blob[$i++]);
                if ($pid === self::DECL_DICT) {
                    [$p, $v] = explode(':', self::DECLS[ord($blob[$i++])], 2);
                    $decls[$p] = $v;
                    continue;
                }
                if ($pid === self::PROP_CUSTOM) {
                    $nl = ord($blob[$i++]);
                    $prop = substr($blob, $i, $nl);
                    $i += $nl;
                } else {
                    $prop = self::PROPS[$pid];
                }
                $decls[$prop] = self::decodeValue($blob, $i);
            }

            $out[$ord] = ['media' => $media, 'pseudo' => $pseudo, 'decls' => $decls];
        }
        return $out;
    }

    /** Decode one value at $i (advances $i). */
    private static function decodeValue(string $b, int &$i): string
    {
        $t = ord($b[$i++]);
        switch ($t) {
            case self::PX16:    $v = ((ord($b[$i]) << 8) | ord($b[$i + 1])) . 'px'; $i += 2; return $v;
            case self::PCT:     return ord($b[$i++]) . '%';
            case self::VH:      return ord($b[$i++]) . 'vh';
            case self::VW:      return ord($b[$i++]) . 'vw';
            case self::REM:     return rtrim(rtrim(sprintf('%.4f', ord($b[$i++]) / 16), '0'), '.') . 'rem';
            case self::EM:      return rtrim(rtrim(sprintf('%.4f', ord($b[$i++]) / 16), '0'), '.') . 'em';
            case self::RGB:     $v = sprintf('#%02x%02x%02x', ord($b[$i]), ord($b[$i + 1]), ord($b[$i + 2])); $i += 3; return $v;
            case self::RGBA:    $v = sprintf('rgba(%d, %d, %d, %s)', ord($b[$i]), ord($b[$i + 1]), ord($b[$i + 2]), rtrim(rtrim(sprintf('%.3f', ord($b[$i + 3]) / 255), '0'), '.')); $i += 4; return $v;
            case self::DECIMAL: return rtrim(rtrim(sprintf('%.2f', ord($b[$i++]) / 100), '0'), '.');
            case self::RAW:     $l = ord($b[$i++]); $v = substr($b, $i, $l); $i += $l; return $v;
            case self::RAW16:   $l = (ord($b[$i]) << 8) | ord($b[$i + 1]); $i += 2; $v = substr($b, $i, $l); $i += $l; return $v;
            case self::PX8:     return ord($b[$i++]) . 'px';
            case self::INT16:   $v = (string) ((ord($b[$i]) << 8) | ord($b[$i + 1])); $i += 2; return $v;
            case self::KEYWORD: return self::KEYWORDS[ord($b[$i++])];
        }
        return '';
    }

    /** CSS rule text for a decoded record (used by tests / references). */
    public static function ruleCss(string $token, array $rec): string
    {
        $body = [];
        foreach ($rec['decls'] as $p => $v) $body[] = "$p:$v";
        $rule = '.' . $token . $rec['pseudo'] . '{' . implode(';', $body) . '}';
        return $rec['media'] !== null ? "{$rec['media']}{{$rule}}" : $rule;
    }

    // ============================================================
    // Class-name helpers (shared with the exotic-prefix decode path)
    // ============================================================

    /** Modifier prefix of a class name (tokens before the base), ':'-joined. */
    public static function prefixOf(string $className): string
    {
        $parts = self::splitParts($className);
        array_pop($parts);
        return implode(':', $parts);
    }

    /**
     * Turn a prefix into [mediaQuery|null, pseudoSelector]. Same rules as
     * StyleGenerator::parseClassName. Used for exotic prefixes.
     * @return array{0:?string,1:string}
     */
    public static function selector(string $prefix): array
    {
        if ($prefix === '') return [null, ''];
        // Media conditions are canonicalized breakpoint-then-scheme (they're
        // commutative in CSS) so this string path and the packed-modifier
        // decode path always render identical rules.
        $bp = null;
        $sc = null;
        $pseudo = '';
        foreach (explode(':', $prefix) as $part) {
            if (isset(self::BREAKPOINTS[$part])) $bp = self::BP_QUERY[self::BREAKPOINTS[$part]];
            elseif (isset(self::SCHEMES[$part])) $sc = self::SC_QUERY[self::SCHEMES[$part]];
            elseif (isset(self::PSEUDO_CSS[$part])) $pseudo .= self::PSEUDO_CSS[$part];
            elseif (str_starts_with($part, 'has-[') && str_ends_with($part, ']')) $pseudo .= ':has(' . substr($part, 5, -1) . ')';
            elseif (str_starts_with($part, 'nth-[') && str_ends_with($part, ']')) $pseudo .= ':nth-child(' . substr($part, 5, -1) . ')';
            elseif (str_starts_with($part, 'not-[') && str_ends_with($part, ']')) $pseudo .= ':not(' . substr($part, 5, -1) . ')';
        }
        $media = array_values(array_filter([$bp, $sc]));
        return [$media !== [] ? '@media ' . implode(' and ', $media) : null, $pseudo];
    }

    /** @return string[] split on ':' respecting [...] brackets */
    private static function splitParts(string $className): array
    {
        $parts = [];
        $cur = '';
        $depth = 0;
        for ($i = 0, $n = strlen($className); $i < $n; $i++) {
            $c = $className[$i];
            if ($c === '[') $depth++;
            elseif ($c === ']') $depth--;
            if ($c === ':' && $depth === 0) {
                if ($cur !== '') $parts[] = $cur;
                $cur = '';
            } else {
                $cur .= $c;
            }
        }
        if ($cur !== '') $parts[] = $cur;
        return $parts;
    }
}
