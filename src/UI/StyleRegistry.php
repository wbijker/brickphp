<?php

namespace BrickPHP\UI;

/**
 * Assigns each utility class a short, STABLE ordinal token (`c0`, `cA`, …) and
 * builds the binary style table the client decodes.
 *
 * Stability matters because of the diff/patch model: the browser keeps a DOM
 * whose class attributes were written in an earlier request, and later patches
 * touch only changed nodes. If an ordinal ever changed meaning, every untouched
 * element would silently restyle. So the map is APPEND-ONLY and persisted: a
 * class keeps its ordinal for the life of the install, across requests and
 * processes. New classes (a screen not seen before) just append.
 *
 * The persisted file: first line `#<seed>` (random epoch id, see below),
 * then one class name per line — the line's position IS the ordinal. Shared
 * per-install (like Hash.php).
 *
 * EPOCH: the seed is generated once when the file is first created and never
 * changes on append — only a file reset (deploy without it, cache wipe) makes
 * a new one. Brick ships it to the client (window.__BRICK_SE) and the client
 * echoes it on every POST; a mismatch means the client's DOM holds ordinals
 * from a previous registry life, so the server answers reload:true instead of
 * letting stale tokens silently restyle.
 *
 * TABLE wire format: see {@see StyleCodec} — records in ascending ordinal
 * order, ordinals implicit (previous + 1) with a varint-gap escape.
 */
final class StyleRegistry
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * Ordinal emission is OFF until {@see registerAll} turns it on for a real
     * page render (GET/POST). So {@see tokenFor} yields null — human class names
     * serialize verbatim — for anything that renders without shipping a style
     * table + decoder: unit tests, and the standalone error page (which inlines
     * its own StyleGenerator CSS with human selectors).
     */
    public static bool $emit = false;

    /** @var array<string,int>|null  class name => ordinal */
    private static ?array $ordinals = null;
    /** @var string[]  ordinal => class name */
    private static array $names = [];
    private static int $next = 0;
    private static bool $dirty = false;
    private static ?string $seed = null;

    private static function file(): string
    {
        // Alongside the generated Hash.php (src/), same writability assumption.
        return __DIR__ . '/../StyleOrdinals.txt';
    }

    /** @return array{0: ?string, 1: string[]} [seed, names] */
    private static function parse(string $raw): array
    {
        $seed = null;
        $names = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') continue;
            if ($seed === null && $names === [] && $line[0] === '#') {
                $seed = substr($line, 1);
                continue;
            }
            $names[] = $line;
        }
        return [$seed, $names];
    }

    private static function load(): void
    {
        if (self::$ordinals !== null) return;
        self::$ordinals = [];
        $raw = @file_get_contents(self::file());
        if ($raw !== false && $raw !== '') {
            [self::$seed, $names] = self::parse($raw);
            foreach ($names as $name) {
                self::$names[self::$next] = $name;
                self::$ordinals[$name] = self::$next;
                self::$next++;
            }
        }
    }

    /**
     * Registry epoch — the seed written when the ordinals file was created.
     * Stable across appends; changes only when the file is reset. Empty string
     * until the first flush creates the file.
     */
    public static function epoch(): string
    {
        self::load();
        return self::$seed ?? '';
    }

    /** base62 token for an ordinal, prefixed so it's a valid CSS ident. */
    public static function token(int $ordinal): string
    {
        $s = '';
        do {
            $s = self::ALPHABET[$ordinal % 62] . $s;
            $ordinal = intdiv($ordinal, 62);
        } while ($ordinal > 0);
        return 'c' . $s;
    }

    /** Ensure a class has an ordinal (assign + queue persist if new). */
    public static function intern(string $class): int
    {
        self::load();
        if (isset(self::$ordinals[$class])) return self::$ordinals[$class];
        $id = self::$next++;
        self::$ordinals[$class] = $id;
        self::$names[$id] = $class;
        self::$dirty = true;
        return $id;
    }

    /**
     * Ordinal token for a KNOWN style class, or null if it isn't one (user
     * classes set via ->class()/attr('class') pass straight through). Does NOT
     * assign — call {@see registerAll} first so the whole page is interned.
     */
    public static function tokenFor(string $class): ?string
    {
        if (!self::$emit) return null;
        self::load();
        $id = self::$ordinals[$class] ?? null;
        return $id === null ? null : self::token($id);
    }

    /**
     * Intern every class in a collected-styles map and persist any additions.
     * @param array<string,array<string,string>> $styles
     */
    public static function registerAll(array $styles): void
    {
        foreach (array_keys($styles) as $class) self::intern($class);
        self::flush();
        self::$emit = true; // a real page render is shipping a style table
    }

    /**
     * Base64 binary table for exactly the classes in $styles (the current page
     * / patched tree). Records are emitted in ascending ordinal order; a
     * record's ordinal is implicit (previous + 1) unless the head byte's GAP
     * flag carries a varint gap — so the contiguous common case costs zero
     * ordinal bytes. The client merges these ordinal→rule entries into its
     * managed stylesheet.
     *
     * @param array<string,array<string,string>> $styles
     */
    public static function tableBase64(array $styles): string
    {
        self::load();
        $byOrdinal = [];
        foreach ($styles as $class => $decls) {
            $ord = self::$ordinals[$class] ?? self::intern($class);
            $byOrdinal[$ord] = [$class, $decls];
        }
        self::flush();
        ksort($byOrdinal);

        $blob = '';
        $prev = -1;
        foreach ($byOrdinal as $ord => [$class, $decls]) {
            $record = StyleCodec::encodeRecord($class, $decls);
            $gap = $ord - $prev;
            $prev = $ord;
            if ($gap === 1) {
                $blob .= $record;
            } else {
                $blob .= chr(ord($record[0]) | StyleCodec::F_GAP)
                    . self::varint($gap)
                    . substr($record, 1);
            }
        }
        return base64_encode($blob);
    }

    /** LEB128 varint (7 bits per byte, high bit = continue). */
    private static function varint(int $v): string
    {
        $out = '';
        while ($v > 127) {
            $out .= chr(($v & 127) | 128);
            $v >>= 7;
        }
        return $out . chr($v);
    }

    private static function flush(): void
    {
        if (!self::$dirty) return;
        self::$dirty = false;
        $file = self::file();
        // Atomic + locked so concurrent appenders don't clobber each other's
        // additions (a dropped class would be re-appended later — but with a
        // fresh ordinal a client may already hold, so avoid it).
        $fh = @fopen($file, 'c+');
        if ($fh === false) return;
        if (flock($fh, LOCK_EX)) {
            // Re-read under lock: another process may have appended since load().
            $onDisk = stream_get_contents($fh);
            if ($onDisk !== false && $onDisk !== '') {
                self::mergeFrom($onDisk);
            }
            self::$seed ??= bin2hex(random_bytes(4)); // first creation: mint the epoch
            $body = '#' . self::$seed . "\n" . (self::$names === [] ? '' : implode("\n", self::$names) . "\n");
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $body);
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }

    /** Rebase the in-memory map onto a newer on-disk copy, preserving order. */
    private static function mergeFrom(string $raw): void
    {
        [$diskSeed, $disk] = self::parse($raw);
        if ($diskSeed !== null) {
            self::$seed = $diskSeed; // keep the first creator's epoch
        }
        if ($disk === []) return;
        // Disk wins for everything it holds; names we added that disk lacks
        // get re-appended on top (fresh ordinals past disk's tail).
        $diskIds = array_flip($disk);
        $pending = [];
        foreach (self::$names as $name) {
            if (!isset($diskIds[$name])) $pending[] = $name;
        }
        self::$names = $disk;
        self::$ordinals = $diskIds;
        self::$next = count($disk);
        foreach ($pending as $class) {
            self::$ordinals[$class] = self::$next;
            self::$names[self::$next] = $class;
            self::$next++;
        }
    }
}
