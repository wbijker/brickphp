<?php

declare(strict_types=1);

use BrickPHP\UI\StyleCodec;
use PHPUnit\Framework\TestCase;

/**
 * Wire-format tests for the binary style codec. The JS mirror in brick.js is
 * verified separately (Node harness comparing rule output byte-for-byte);
 * these lock the PHP side and the format invariants.
 */
class StyleCodecTest extends TestCase
{
    /** Decode a single record as a one-record table (ordinal 0). */
    private function roundTrip(string $class, array $decls): array
    {
        $blob = StyleCodec::encodeRecord($class, $decls);
        $table = StyleCodec::decodeTable($blob);
        $this->assertArrayHasKey(0, $table);
        return $table[0];
    }

    public function testSimpleColorDeclaration(): void
    {
        $rec = $this->roundTrip('bg-green-400', ['background-color' => '#4ade80']);
        $this->assertSame(['background-color' => '#4ade80'], $rec['decls']);
        $this->assertNull($rec['media']);
        $this->assertSame('', $rec['pseudo']);
    }

    public function testWholeDeclarationDictionaryIsThreeBytes(): void
    {
        $blob = StyleCodec::encodeRecord('flex', ['display' => 'flex']);
        $this->assertSame(3, strlen($blob)); // head + 0xFE + declId
        $this->assertSame(['display' => 'flex'], StyleCodec::decodeTable($blob)[0]['decls']);
    }

    public function testPackedModifier(): void
    {
        $rec = $this->roundTrip('lg:hover:bg-x', ['background-color' => '#297fff']);
        $this->assertSame('@media (min-width: 1024px)', $rec['media']);
        $this->assertSame(':hover', $rec['pseudo']);
    }

    public function testChainedPseudosUseExtendedModifier(): void
    {
        $rec = $this->roundTrip('md:first:hover:active:bg-green-400', ['background-color' => '#4ade80']);
        $this->assertSame('@media (min-width: 768px)', $rec['media']);
        // Source order preserved.
        $this->assertSame(':first-child:hover:active', $rec['pseudo']);
        $this->assertSame(['background-color' => '#4ade80'], $rec['decls']);
    }

    public function testSchemeAndBreakpointCanonicalOrder(): void
    {
        // dark:lg and lg:dark must render the same @media (bp first).
        $a = $this->roundTrip('dark:lg:text-white', ['color' => '#ffffff']);
        $b = $this->roundTrip('lg:dark:text-white', ['color' => '#ffffff']);
        $this->assertSame('@media (min-width: 1024px) and (prefers-color-scheme: dark)', $a['media']);
        $this->assertSame($a['media'], $b['media']);
    }

    public function testExoticPrefixEscape(): void
    {
        $rec = $this->roundTrip('has-[img]:p-4', ['padding' => '16px']);
        $this->assertSame(':has(img)', $rec['pseudo']);
        $this->assertSame(['padding' => '16px'], $rec['decls']);
    }

    public function testCustomPropertyEscape(): void
    {
        $rec = $this->roundTrip('x', ['scroll-margin-top' => '64px']);
        $this->assertSame(['scroll-margin-top' => '64px'], $rec['decls']);
    }

    public function testValueShapes(): void
    {
        $values = [
            'width'          => '9999px',   // PX16
            'height'         => '46px',     // PX8
            'min-width'      => '100%',     // PCT
            'min-height'     => '100vh',    // VH
            'font-size'      => '1.125rem', // REM ÷16
            'top'            => '1.3125em', // EM ÷16 (fractional, lossless)
            'opacity'        => '0.5',      // DECIMAL ÷100
            'font-weight'    => '500',      // INT16
            'z-index'        => '9999',     // INT16
            'cursor'         => 'pointer',  // KEYWORD
            'box-shadow'     => '0 1px 2px 0 rgb(0 0 0 / 0.05)', // RAW
            'margin-top'     => '-4px',     // RAW (negative)
        ];
        $rec = $this->roundTrip('v', $values);
        $this->assertSame($values, $rec['decls']);
    }

    public function testMultipleDeclarations(): void
    {
        $decls = ['padding-left' => '14px', 'padding-right' => '14px'];
        $this->assertSame($decls, $this->roundTrip('px-x', $decls)['decls']);
    }

    public function testImplicitOrdinalsAndVarintGap(): void
    {
        $a = StyleCodec::encodeRecord('a', ['display' => 'flex']);
        $b = StyleCodec::encodeRecord('b', ['cursor' => 'pointer']);
        $c = StyleCodec::encodeRecord('c', ['color' => '#ffffff']);
        // ordinals 0, 1 (implicit), then gap 200 → ordinal 201 (varint 0xC8 0x01)
        $blob = $a . $b
            . chr(ord($c[0]) | StyleCodec::F_GAP) . chr(0xC8) . chr(0x01) . substr($c, 1);
        $table = StyleCodec::decodeTable($blob);
        $this->assertSame([0, 1, 201], array_keys($table));
        $this->assertSame(['color' => '#ffffff'], $table[201]['decls']);
    }

    public function testRuleCss(): void
    {
        $blob = StyleCodec::encodeRecord('lg:hover:bg-x', ['background-color' => '#297fff']);
        $rec = StyleCodec::decodeTable($blob)[0];
        $this->assertSame(
            '@media (min-width: 1024px){.c0:hover{background-color:#297fff}}',
            StyleCodec::ruleCss('c0', $rec),
        );
    }
}
