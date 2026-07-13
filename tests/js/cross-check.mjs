// PHP ↔ JS codec cross-check.
//
// Spawns `php bin/dump-css-cases.php` for the authoritative {name, css} pairs
// (PHP is the source of truth), then asserts BrickCss.rule(name) reproduces each
// one byte-for-byte. Fails if the JS decoder or its generated tables drift from
// the PHP enums — e.g. a new CssProperty whose alias wasn't regenerated into
// assets/brick_css.js via bin/gen-css-tables.php.
//
//   node tests/js/cross-check.mjs
import { createRequire } from 'module';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const require = createRequire(import.meta.url);
const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const BrickCss = require(join(root, 'assets', 'brick_css.js'));

let cases;
try {
    const json = execFileSync('php', [join(root, 'bin', 'dump-css-cases.php')], {
        encoding: 'utf8', maxBuffer: 32 * 1024 * 1024,
    });
    cases = JSON.parse(json);
} catch (e) {
    console.error('failed to load PHP fixtures (is `php` on PATH?):', e.message);
    process.exit(2);
}

let failed = 0;
for (const { name, css } of cases) {
    let got;
    try {
        got = BrickCss.rule(name);
    } catch (e) {
        got = 'THREW: ' + e.message;
    }
    if (got !== css) {
        failed++;
        console.error(`MISMATCH  ${name}\n   php: ${css}\n   js:  ${got}`);
    }
}

if (failed) {
    console.error(`\n${failed}/${cases.length} mismatched — PHP and JS codecs have drifted.`);
    console.error('If you changed a Style enum, run: php bin/gen-css-tables.php');
    process.exit(1);
}
console.log(`ok — ${cases.length} class names reproduced identically by PHP and JS.`);
