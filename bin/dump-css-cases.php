<?php

declare(strict_types=1);

/**
 * Emit authoritative {name, css} pairs for the whole Style surface as a JSON
 * array on stdout — the fixtures the JS cross-check (tests/js/cross-check.mjs)
 * runs BrickCss.rule() against. PHP is the source of truth; every enum entry is
 * covered, so adding one without regenerating assets/brick_css.js fails the JS
 * check (the new token has no JS table entry → mismatch).
 *
 *   php bin/dump-css-cases.php
 */

namespace BrickPHP\Style;

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'BrickPHP\\')) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

/** @var list<array{name:string,css:string}> */
$cases = [];
$emit = static function (CssClass $c) use (&$cases): void {
    $name = $c->name();
    $cases[] = ['name' => $name, 'css' => CssClass::cssFor($name)];
};

$val = CssStaticValue::raw('1px');

// Every property alias — catches a missing/renamed JS prop entry.
foreach (CssProperty::cases() as $p) {
    $emit(CssClass::of($p, $val));
}

// Every variant token — catches a missing JS media/supports/combinator/pseudo entry.
foreach (CssMedia::cases() as $m) {
    $emit(CssClass::of(CssProperty::color, $val)->media($m));
}
foreach (CssSupports::cases() as $s) {
    $emit(CssClass::of(CssProperty::color, $val)->supports($s));
}
foreach (CssCombinator::cases() as $co) {
    $emit(CssClass::of(CssProperty::color, $val)->combinator($co));
}
foreach (CssPseudoClass::cases() as $pc) {
    $emit(CssClass::of(CssProperty::color, $val)->pseudo($pc));
}
foreach (CssPseudoElement::cases() as $pe) {
    $emit(CssClass::of(CssProperty::content, CssStaticValue::raw('""'))->element($pe));
}

// Value shapes and a full stack — exercises escaping and nesting.
$emit(CssClass::of(CssProperty::backgroundColor, CssColor::hex(0xFF00F2)));
$emit(CssClass::of(CssProperty::margin, CssStaticValue::raw('0 auto')));
$emit(CssClass::of(CssProperty::width, CssStaticValue::raw('calc(100% - 2rem)')));
$emit(
    CssClass::of(CssProperty::color, CssColor::hex(0x112233))
        ->media(CssMedia::lg, CssMedia::dark)
        ->supports(CssSupports::grid)
        ->pseudo(CssPseudoClass::hover, CssPseudoClass::focus)
        ->element(CssPseudoElement::before),
);

echo json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
