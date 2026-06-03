<?php

namespace BrickPHP;

use BrickPHP\Debug\DebugPanel;
use BrickPHP\Debug\WireframeRenderer;
use BrickPHP\Debug\Timings;
use BrickPHP\Error\DefaultErrorPage;
use BrickPHP\Error\ErrorInfo;
use BrickPHP\Js\Js;
use BrickPHP\State\InMemoryStateManager;
use BrickPHP\State\StateManager;
use BrickPHP\UI\StyleGenerator;
use BrickPHP\UI\TagDomNode;
use BrickPHP\UI\UIElement;
use BrickPHP\VNode\App;
use BrickPHP\VNode\Component;
use BrickPHP\VNode\Patcher;
use BrickPHP\VNode\PortalTarget;
use BrickPHP\VNode\RenderPhase;
use BrickPHP\VNode\VNode;
use Throwable;

class Brick
{
    /** @var App|null The current app instance, kept so the shutdown handler can call its error() method. */
    private static ?App $current = null;

    /** @var bool Set once an error has been rendered so the shutdown handler doesn't render twice. */
    private static bool $errorRendered = false;

    /** @var float microtime when run() entered — start of the app's own PHP code execution. */
    private static float $runStart = 0.0;

    /**
     * Inline stylesheet for wireframe mode. Loaded only when the page is
     * rendered with ?wireframe=true. Keeps the original element box (so
     * margins/paddings/sizing stay intact) and overlays a dashed outline +
     * a small top-left tag with the construct/component name. The image
     * placeholder uses two CSS gradients to draw the classic crossed-out
     * rectangle so a sized <img> turns into a same-sized X box.
     */
    private const WIREFRAME_CSS = <<<'CSS'
.brick-wf{outline:1px dashed rgba(60,80,120,0.45);outline-offset:-1px;position:relative;}
.brick-wf-label{position:absolute;top:0;left:0;z-index:9999;font:10px/1 ui-monospace,SFMono-Regular,Menlo,monospace;background:#ffec99;color:#222;padding:2px 5px;pointer-events:none;border-bottom-right-radius:3px;white-space:nowrap;letter-spacing:.02em;}
.brick-wf-img{background:
  linear-gradient(to top right,transparent calc(50% - 1px),rgba(60,80,120,0.4) 50%,transparent calc(50% + 1px)),
  linear-gradient(to top left,transparent calc(50% - 1px),rgba(60,80,120,0.4) 50%,transparent calc(50% + 1px));min-height:32px;}
.brick-wf-hover{background-color:rgba(255,235,100,0.45) !important;}
CSS;

    /**
     * Entry point. Accepts either an instantiated App or its class
     * name. Passing the class name is preferred: it lets Brick install
     * its error traps BEFORE the App is constructed, so a parse error
     * or constructor crash in the App's own file is caught and shown
     * as an error page instead of leaking xdebug's HTML.
     *
     * @param App|class-string<App> $entry
     */
    public static function run(App|string $entry): void
    {
        self::serveStaticAsset();
        self::dispatch($entry);
    }

    /**
     * Per-URL dispatch — runs after static-asset serving and decides
     * which framework entry point handles the request. Today the only
     * special route is `/hmr.php` (long-polled by the dev HMR client in
     * `brick.js`); everything else flows into the normal app render.
     *
     * Consumers can keep a one-line `hmr.php` next to `index.php` — both
     * just call `Brick::run(MyApp::class)` — so Apache serves `/hmr.php`
     * directly without needing a rewrite rule. The dispatch is the same
     * either way.
     */
    private static function dispatch(App|string $entry): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($path === '/hmr.php') {
            self::watch($entry);
            return;
        }
        self::doRun($entry);
    }

    /**
     * Map of framework runtime assets the consumer's `index.php` needs to
     * make available at well-known URLs. Brick injects `<script>` /
     * `<link>` tags that reference these paths, so any unrecognised
     * request below falls through to the normal `run()` flow.
     *
     * Consumers can call this directly before `run()`; `run()` also calls
     * it transparently so the assets just work without extra wiring:
     *
     *   <?php
     *   require 'vendor/autoload.php';
     *   Brick::run(App::class);  // <- handles /brick.js etc. automatically
     */
    public static function serveStaticAsset(?string $path = null): bool
    {
        $path ??= parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        static $assets = [
            '/brick.js'       => ['file' => 'brick.js',       'type' => 'application/javascript'],
            '/brick_debug.js' => ['file' => 'brick_debug.js', 'type' => 'application/javascript'],
            '/router.js'      => ['file' => 'router.js',      'type' => 'application/javascript'],
            '/preflight.css'  => ['file' => 'preflight.css',  'type' => 'text/css'],
        ];

        if (!isset($assets[$path])) {
            return false;
        }

        $a = $assets[$path];
        $file = self::assetPath($a['file']);
        if (!is_file($file)) {
            return false;
        }

        header('Content-Type: ' . $a['type'] . '; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /**
     * Absolute path to an asset shipped with the library. Useful for
     * consumers who want to read the file directly (e.g. concatenate
     * `preflight.css` into a custom stylesheet endpoint) instead of
     * round-tripping through the HTTP server.
     */
    public static function assetPath(string $name): string
    {
        return __DIR__ . '/../assets/' . $name;
    }

    private static function doRun(App|string $entry): void
    {
        // Start of the app's own code execution — everything before this is
        // PHP engine startup + compiling index.php/autoload (the gap between
        // this and REQUEST_TIME_FLOAT). See codeMs() / phpMs().
        self::$runStart = microtime(true);

        self::installErrorTraps();

        try {
            if (is_string($entry)) {
                $entry = new $entry();
            }
            self::$current = $entry;

            // Dev mode flips the capture flag for the whole request — every
            // UIElement::__construct walks the call stack once to stamp
            // file:line on its DOM node so ctrl+click in the page can log
            // "this came from News.php:75". Set before handlePost so it
            // covers the OLD/NEW rebuilds. Captured paths are rewritten
            // through host_root so they survive the container boundary when
            // the dev's editor opens the link.
            UIElement::$captureSource = Config::$development;
            if (Config::$development) {
                UIElement::$sourceRoot = rtrim(dirname($_SERVER['SCRIPT_FILENAME'] ?? '', 2), '/');
                UIElement::$hostRoot = rtrim(self::editorHostRoot() ?? UIElement::$sourceRoot, '/');
            }

            // Section timer for the per-request console breakdown. Started
            // here so the first section ("restore state") captures the state
            // manager load; the handlers mark the remaining sections.
            $t = new Timings();

            // Pull the state manager ONCE; do not call state() again.
            // Many managers are stateful within a request (in-process
            // caches), so two instances would have divergent caches and
            // the request would see inconsistent data.
            $state = $entry->state();
            $t->mark('restore state');

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                self::handlePost($entry, $state, $t);
            } else {
                self::handleGet($entry, $state, $t);
            }
        } catch (Throwable $e) {
            self::renderError(ErrorInfo::fromThrowable($e));
        }
    }

    /**
     * HMR endpoint — long-polls for source changes and publishes the current
     * fingerprint into the Hash class. Drive it from www/hmr.php:
     *
     *   Brick::watch(NewsApp::class);
     *
     * Config-aware via the static Config (no config file): when
     * Config::$development is off it short-circuits. While polling it walks
     * the source (sourceHash); on a change it rewrites Hash and tells the
     * client to reload. Writing Hash at the start also catches edits made
     * while no poll was running.
     *
     * @param App|class-string<App> $entry
     */
    public static function watch(App|string $entry): void
    {
        if (is_string($entry)) {
            $entry = new $entry();
        }
        self::$current = $entry;

        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');

        // Production short-circuit — the client only polls in dev, but a
        // direct hit shouldn't burn worker time when HMR is off.
        if (!Config::$development) {
            echo json_encode(['changed' => false]);
            return;
        }

        ignore_user_abort(false);
        @set_time_limit(70);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $deadline = microtime(true) + 60;
        $baseline = self::sourceHash();
        self::writeHash($baseline);

        while (microtime(true) < $deadline) {
            clearstatcache();
            $current = self::sourceHash();
            if ($current !== $baseline) {
                self::writeHash($current);
                echo json_encode(['changed' => true]);
                return;
            }
            echo ' ';
            flush();
            if (connection_aborted()) {
                return;
            }
            usleep(300_000);
        }

        echo json_encode(['changed' => false]);
    }

    /**
     * Install error/exception/shutdown traps so every flavor of PHP
     * error lands in {@see renderError} instead of xdebug's default
     * HTML or a half-rendered page. Also starts an output buffer so
     * any error markup written by the SAPI (e.g. xdebug) can be
     * discarded before we emit our own.
     */
    private static function installErrorTraps(): void
    {
        // Don't let PHP/xdebug emit its own HTML. We render everything.
        @ini_set('display_errors', '0');
        @ini_set('html_errors', '0');

        // Output buffer so xdebug's pretty error HTML (and any partial
        // app output written before the crash) can be discarded.
        ob_start();

        // Fatal-class errors thrown at runtime become exceptions so
        // the normal catch path in run() / handleGet() / handlePost()
        // picks them up. Non-fatal errors are returned to PHP's
        // default handler so they don't break otherwise-fine pages.
        set_error_handler(function (int $errno, string $msg, string $file, int $line) {
            if ((error_reporting() & $errno) === 0) {
                return false;
            }
            if (in_array($errno, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
                throw new \ErrorException($msg, 0, $errno, $file, $line);
            }
            return false;
        });

        // Backstop for anything that escapes our try/catch.
        set_exception_handler(function (Throwable $e) {
            self::renderError(ErrorInfo::fromThrowable($e));
        });

        // Parse errors, out-of-memory, and other unrecoverables that
        // never reach an exception handler still surface here.
        register_shutdown_function(function () {
            if (self::$errorRendered) {
                return;
            }
            $err = error_get_last();
            if ($err !== null && ErrorInfo::isFatal($err['type'])) {
                self::renderError(ErrorInfo::fromLastError($err));
            }
        });
    }

    /**
     * Render an error page (HTML for GET, JSON reload for POST). Safe
     * to call from any point in the request — clears any buffered
     * partial output first so xdebug's HTML doesn't bleed through.
     */
    private static function renderError(ErrorInfo $info): void
    {
        if (self::$errorRendered) {
            return;
        }
        self::$errorRendered = true;

        // Drop any buffered output (including xdebug's error HTML).
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

        // For a POST, the frontend expects JSON. Asking it to reload
        // bounces us back to GET, which re-renders the error page
        // through the HTML path below.
        if ($isPost) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
                http_response_code(500);
            }
            echo json_encode(['success' => false, 'reload' => true]);
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            http_response_code(500);
        }

        // Try the app's error() method; fall back to DefaultErrorPage
        // directly if no app exists yet (e.g. parse error during
        // construction) or if error() itself throws.
        $errorView = null;
        if (self::$current !== null) {
            try {
                $errorView = self::$current->error($info);
            } catch (Throwable) {
                $errorView = null;
            }
        }
        if (!$errorView instanceof VNode) {
            $errorView = new DefaultErrorPage($info);
        }

        try {
            $state = new InMemoryStateManager();
            $dom = $errorView->render($state, null, RenderPhase::Initial);
            $styles = $dom->collectStyles();
            $css = StyleGenerator::from($styles)->toStyle();

            $head = (new TagDomNode('head'))->content(
                (new TagDomNode('meta'))->attr('charset', 'UTF-8'),
                (new TagDomNode('meta'))->attr('name', 'viewport')->attr('content', 'width=device-width, initial-scale=1.0'),
                (new TagDomNode('title'))->rawContent('Error'),
                (new TagDomNode('style'))->rawContent($css),
            );
            $body = (new TagDomNode('body'))
                ->attr('style', 'margin:0')
                ->content($dom);
            if (self::isDevelopment()) {
                $body->content((new TagDomNode('script'))->rawContent(self::hmrScript()));
            }
            $document = (new TagDomNode('html'))
                ->attr('lang', 'en')
                ->content($head, $body);

            echo '<!DOCTYPE html>' . $document->toHtml();
        } catch (Throwable) {
            // Absolute last resort: build the default page directly
            // and emit it without going through render/collectStyles.
            $bare = new DefaultErrorPage($info);
            $dom = $bare->render(new InMemoryStateManager(), null, RenderPhase::Initial);
            $hmr = self::isDevelopment() ? '<script>' . self::hmrScript() . '</script>' : '';
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body style="margin:0">'
               . $dom->toHtml()
               . $hmr
               . '</body></html>';
        }
    }

    /**
     * True when the app's config has development on. Gates the HMR long-poll
     * (the IIFE in brick.js via window.__BRICK_DEV, the inlined error-page
     * script, and the /hmr.php endpoint itself).
     */
    public static function isDevelopment(): bool
    {
        return Config::$development;
    }

    /**
     * Editor jump-to-source URL template used by ctrl+click. Empty when
     * unconfigured — the inspector falls back to console.log.
     */
    public static function editorUrlTemplate(): string
    {
        return Config::$editorUrl;
    }

    /**
     * Host-side absolute path of this project — the prefix the editor link
     * needs so the OS can find the file when PHP runs under a different mount
     * path (Docker, VM, etc.). Null when unset — UIElement falls back to the
     * auto-detected server root and the rewrite is a no-op.
     */
    public static function editorHostRoot(): ?string
    {
        $v = Config::$editorHostRoot;
        return ($v !== null && $v !== '') ? $v : null;
    }

    /**
     * Inline HMR polling — same semantics as the IIFE at the bottom of
     * brick.js, but emitted directly into the error page so a fix to
     * the offending file triggers a reload without the user touching
     * the browser. Inlined (rather than `<script src="/brick.js">`)
     * because brick.js's bootstrap() runs maybeReplay() which would
     * re-fire the failed POST against the still-broken backend.
     */
    private static function hmrScript(): string
    {
        return <<<'JS'
(function () {
    var ctl;
    function poll() {
        if (ctl) ctl.abort();
        ctl = new AbortController();
        fetch('/hmr.php', { signal: ctl.signal, cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (j && j.changed) location.reload(); })
            .catch(function () {});
    }
    window.addEventListener('beforeunload', function () { if (ctl) ctl.abort(); });
    poll();
    setInterval(poll, 60000);
})();
JS;
    }

    /**
     * Fingerprint the stored state. Used as a cheap optimistic-
     * concurrency token: the frontend echoes back the hash from page
     * render, the backend re-hashes the stored state before processing
     * an event, and a mismatch forces a reload.
     */
    private static function computeStateHash(StateManager $state): string
    {
        return sha1(serialize($state->getAll()));
    }

    /**
     * Milliseconds of the app's own code execution — from run() entering
     * (self::$runStart) to now. Excludes the PHP startup + compile that
     * happened before run() was reached.
     */
    private static function codeMs(): float
    {
        $start = self::$runStart ?: microtime(true);
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Milliseconds of total PHP time for this request, measured from when the
     * SAPI received it (REQUEST_TIME_FLOAT). Includes everything codeMs()
     * does plus the pre-run engine startup + bootstrap compile; the gap
     * phpMs() - codeMs() is that fixed bootstrap cost.
     */
    private static function phpMs(): float
    {
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Perf-relevant extension state for the running SAPI, shipped to the
     * client so the timing logs show what was active: opcache + apcu speed
     * requests up, xdebug slows them down. Lets you read a benchmark number
     * together with the config that produced it.
     *
     * @return array{xdebug: bool, opcache: bool, apcu: bool}
     */
    private static function phpEnv(): array
    {
        $opcache = function_exists('opcache_get_status')
            && is_array($s = @opcache_get_status(false))
            && !empty($s['opcache_enabled']);

        return [
            'xdebug'  => extension_loaded('xdebug'),
            'opcache' => $opcache,
            'apcu'    => function_exists('apcu_enabled') && apcu_enabled(),
        ];
    }

    /** Absolute path of the generated Hash class file (brickphp/src/Hash.php). */
    private static function hashFile(): string
    {
        return __DIR__ . '/Hash.php';
    }

    /**
     * Rewrite the BrickPHP\Hash class with the given source fingerprint. Called
     * from Brick::watch (hmr) and the bootstrap in styleVersion(). Written
     * atomically (temp + rename) so a concurrent autoload never sees a
     * half-written file; skips the write when the value is unchanged.
     */
    public static function writeHash(string $hash): void
    {
        $file = self::hashFile();
        $content = "<?php\n\n"
            . "namespace BrickPHP;\n\n"
            . "// AUTO-GENERATED — do not edit by hand. The \$value is rewritten by\n"
            . "// hmr.php (Brick::watch) with the current source fingerprint and read\n"
            . "// by Brick::styleVersion() as the /style.css cache-buster.\n"
            . "class Hash\n{\n    public static string \$value = " . var_export($hash, true) . ";\n}\n";

        if (@file_get_contents($file) === $content) {
            return; // unchanged — skip the write
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $content) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }

    /**
     * /style.css cache-buster. Reads the fingerprint hmr.php wrote into the
     * Hash class — the normal request flow never scans the source. The class
     * file is gitignored (auto-generated), so on a fresh checkout it may not
     * exist yet; in that case we write it BEFORE referencing Hash::$value, so
     * the autoloader has something to find when the class is first touched.
     * Same path handles the empty-$value bootstrap (file existed but hmr
     * hadn't populated it).
     */
    private static function styleVersion(): string
    {
        if (!file_exists(self::hashFile())) {
            $hash = self::sourceHash();
            self::writeHash($hash);
            return $hash;
        }
        if (Hash::$value !== '') {
            return Hash::$value;
        }
        $hash = self::sourceHash();
        self::writeHash($hash);
        return $hash;
    }

    /**
     * Full source fingerprint: newest mtime + file count, joined with a
     * colon. The HMR change signal — it must watch every source file,
     * including ones the current request never loaded, so it walks .php and
     * .css under the config's sourceDir, pruning the sourceExclude basenames.
     * An edit bumps mtime, an add/remove bumps count.
     */
    public static function sourceHash(): string
    {
        $configDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
        $root = Config::$sourceDir;
        if ($root === '' || $root[0] !== '/') {
            $root = $configDir . '/' . $root;
        }
        $skip = array_flip(Config::$sourceExclude);
        // Never fingerprint our own generated Hash class — writing it would
        // bump its mtime and falsely trip the next change check (reload loop).
        $skip['Hash.php'] = true;

        $dir = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($dir, function ($f) use ($skip) {
            if (isset($skip[$f->getFilename()])) return false;
            if ($f->isFile()) {
                $ext = $f->getExtension();
                return $ext === 'php' || $ext === 'css';
            }
            return true;
        });
        $max = 0;
        $n = 0;
        foreach (new \RecursiveIteratorIterator($filter) as $f) {
            $m = $f->getMTime();
            if ($m > $max) $max = $m;
            $n++;
        }
        // Append the lowercased FQCN of the entry app so two apps sharing the
        // same library install produce distinct fingerprints (and thus distinct
        // /style.css?h=... cache keys) even when their source trees happen to
        // hash identically.
        $klass = self::$current !== null ? strtolower(self::$current::class) : '';
        return $max . ':' . $n . ':' . $klass;
    }

    private static function handlePost(App $entry, StateManager $state, Timings $t): void
    {
        // A full-page (client=false) event arrives as a real form POST with
        // $_POST['_brickEvent'] set (see submitForm in brick.js). It's answered
        // with a freshly rendered HTML document, not JSON patches — handle it
        // on its own path.
        if (isset($_POST['_brickEvent'])) {
            self::handleNavPost($entry, $state, $t);
            return;
        }

        ob_start();

        // Asset registration also installs event-hydrators (EventData::register)
        // for framework extensions like Leaflet. Run it on POST too so the
        // server knows how to hydrate custom event names that arrive in the
        // payload — the addScript / addStyleInline side-effects are harmless
        // here (we don't re-emit <head> on a POST response).
        $entry->runRegisterAssets();

        // Parse payload from JSON or multipart form
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'multipart/form-data')) {
            $payload = json_decode($_POST['_brick'] ?? '{}', true);
        } else {
            $payload = json_decode(file_get_contents('php://input'), true);
        }

        $event = $payload['event'] ?? '';
        $pathStr = $payload['path'] ?? '';
        // Empty path = the root element itself. explode(',', '') returns
        // [''] which intvals to [0] (== the first child), so guard.
        $path = $pathStr === '' ? [] : array_map('intval', explode(',', $pathStr));
        $value = $payload['value'] ?? null;
        $bindings = $payload['bindings'] ?? [];
        $expectedHash = $payload['hash'] ?? null;
        // The path the frontend's DOM was rendered for. Differs from
        // REQUEST_URI after popstate (browser changed URL, server hasn't
        // re-rendered yet). Used to override REQUEST_URI for the OLD render
        // only, so the diff against the NEW render at the current URL
        // produces the correct list↔detail swap.
        $previousPath = $payload['previousPath'] ?? null;
        $t->mark('parse payload');

        // Optimistic concurrency: the frontend echoes the hash it was
        // rendered against. If the backend's current state hashes differently
        // (e.g. another tab mutated it, or a deploy reshaped it), the frontend
        // is operating on a stale tree — bail out and force a reload.
        if ($expectedHash !== null && $expectedHash !== self::computeStateHash($state)) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'reload' => true]);
            exit;
        }
        $t->mark('verify hash');

        // Render the old tree, execute event, save state. If the render or
        // event handler crashes because serialized state no longer matches
        // the current code's shape, clear all state and tell the client to
        // reload — the fresh page will start from defaults.
        try {
            PortalTarget::reset();
            $oldApp = new ($entry::class)();
            // OLD must mirror what's actually on the user's screen — the path
            // the frontend last rendered for. After popstate the browser URL
            // (REQUEST_URI) has already moved on; without this override OLD
            // would render the NEW route too, diff would be empty, and the
            // stale DOM would never be patched.
            $origRequestUri = $_SERVER['REQUEST_URI'] ?? null;
            if ($previousPath !== null && $previousPath !== '' && $previousPath !== $origRequestUri) {
                $_SERVER['REQUEST_URI'] = $previousPath;
            }
            $oldUi = $oldApp->render($state, null, RenderPhase::DiffOld);
            if ($origRequestUri !== null) {
                $_SERVER['REQUEST_URI'] = $origRequestUri;
            }
            $t->mark('render old');

            if (!empty($bindings) && $oldUi instanceof TagDomNode) {
                $oldUi->hydrateBindings($bindings);
            }
            $t->mark('hydrate bindings');

            $node = $oldUi->findByPath($path);
            if ($node !== null) {
                $node->executeEvent($event, $state, $value);
            }
            $t->mark('perform action');

            // Finalize every old-tree component, not just the root. An event
            // handler can mutate state on any ancestor component via captured
            // `$this`; without this sweep those mutations never persist.
            Component::finalizeAll($state);
            $t->mark('saving state');

            PortalTarget::reset();
            $newApp = new ($entry::class)();
            $newUi = $newApp->render($state, null, RenderPhase::Patch);
            $t->mark('render new');
        } catch (\Throwable $e) {
            $state->clearAll();
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'reload' => true]);
            exit;
        }

        // Lifecycle: deleted (old tree components not in new tree)
        Component::processDeleted();
        $t->mark('process deleted');

        // Diff
        $patcher = new Patcher();
        $newUi->compare($oldUi, $patcher);
        $t->mark('diffing');

        // Event (de)registration rides on the diff itself: compare() above
        // emits bind/unbind as nodes are inserted, replaced, removed, or
        // change their listeners. Nothing extra to do here.

        // Capture buffered output → console.log
        $output = ob_get_clean();
        if ($output !== '' && $output !== false) {
            Js::run(Js::invoke(Js::obj('console', 'log'), Js::str($output)));
        }

        $newHash = self::computeStateHash($state);
        $t->mark('compute hash');

        // Serialize the patch nodes to HTML — the "output of patches" cost.
        $patches = $patcher->getOperations();
        $t->mark('output patches');

        // Debug panel → console (prepended so it appears first)
        $appCalls = Js::drain();
        (new DebugPanel($newUi, $state, $t))->emit();
        $debugCalls = Js::drain();
        Js::prepend(array_merge($debugCalls, $appCalls));

        $response = [
            'success' => true,
            'js' => Js::dump(),
            'patches' => $patches,
            'hash' => $newHash,
        ];

        $clientState = $state->getClientState();
        if ($clientState !== null) {
            $response['state'] = $clientState;
        }

        // Server timings the client logs against its own round-trip:
        // codeMs = app code execution, phpMs = total PHP time (incl. bootstrap),
        // sections = per-phase breakdown of codeMs.
        $response['codeMs'] = self::codeMs();
        $response['phpMs'] = self::phpMs();
        $response['env'] = self::phpEnv();
        $response['sections'] = $t->all();

        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    /**
     * Handle a full-page (client=false) event POST. The browser submitted a
     * real form rather than an AJAX request, so we execute the event against
     * the tree the user had on screen, persist the resulting state, then render
     * a complete HTML document for the current URL — a true navigation, not an
     * in-place patch. If restoring/executing against drifted state throws, the
     * state is cleared and a clean page is rendered instead.
     */
    private static function handleNavPost(App $entry, StateManager $state, Timings $t): void
    {
        $event = $_POST['_brickEvent'] ?? '';
        $pathStr = $_POST['_brickPath'] ?? '';
        // Empty path = the root element itself (see handlePost for the [''] → [0] guard).
        $path = $pathStr === '' ? [] : array_map('intval', explode(',', $pathStr));
        $value = json_decode($_POST['_brickValue'] ?? 'null', true);
        $bindings = isset($_POST['_brickBindings']) ? (json_decode($_POST['_brickBindings'], true) ?: []) : [];
        $previousPath = $_POST['_brickPrevPath'] ?? null;
        $t->mark('parse payload');

        try {
            PortalTarget::reset();
            $oldApp = new ($entry::class)();
            // Custom event-name hydrators (e.g. Leaflet) are installed in
            // registerAssets; run it on the throwaway OLD app so executeEvent
            // can hydrate them. $entry stays untouched, so handleGet's own
            // runRegisterAssets won't double-register the page's assets.
            $oldApp->runRegisterAssets();

            // OLD must mirror what was on the user's screen — the path the page
            // was rendered for, which can differ from REQUEST_URI.
            $origRequestUri = $_SERVER['REQUEST_URI'] ?? null;
            if ($previousPath !== null && $previousPath !== '' && $previousPath !== $origRequestUri) {
                $_SERVER['REQUEST_URI'] = $previousPath;
            }
            $oldUi = $oldApp->render($state, null, RenderPhase::DiffOld);
            if ($origRequestUri !== null) {
                $_SERVER['REQUEST_URI'] = $origRequestUri;
            }

            if (!empty($bindings) && $oldUi instanceof TagDomNode) {
                $oldUi->hydrateBindings($bindings);
            }

            $node = $oldUi->findByPath($path);
            if ($node !== null) {
                $node->executeEvent($event, $state, $value);
            }

            // Persist mutations from every old-tree component, not just the
            // event owner (an ancestor's captured $this may have been mutated).
            Component::finalizeAll($state);
            $t->mark('perform action');
        } catch (\Throwable $e) {
            // Serialized state no longer matches the code's shape — drop it and
            // render the page from defaults.
            $state->clearAll();
        }

        // Render the full page from the (now-mutated) state. pushClientState
        // re-seeds client-side storage so a localStorage / sessionStorage
        // manager reflects the event across the navigation.
        PortalTarget::reset();
        self::handleGet($entry, $state, $t, true);
    }

    private static function handleGet(App $entry, StateManager $state, Timings $t, bool $pushClientState = false): void
    {
        // Wireframe is a dev-only tool — production renders ignore
        // ?wireframe= entirely. Source capture (UIElement::$captureSource) is
        // already on for the whole request when isDevelopment, set in run().
        $isDev = self::isDevelopment();
        $wireframe = $isDev && (bool)filter_input(INPUT_GET, 'wireframe', FILTER_VALIDATE_BOOLEAN);

        // If restoring serialized state crashes the render, drop all state
        // and retry from defaults. A second failure is propagated.
        try {
            PortalTarget::reset();
            $entry->runRegisterAssets();
            $ui = $entry->render($state, null, RenderPhase::Initial);
        } catch (\Throwable $e) {
            $state->clearAll();
            PortalTarget::reset();
            $entry = new ($entry::class)();
            $entry->runRegisterAssets();
            $ui = $entry->render($state, null, RenderPhase::Initial);
        }
        $t->mark('render');

        $entry->finalize($state);
        $t->mark('saving state');

        // Wireframe transform after the real render so the original styles
        // (margins, paddings, sizing) are preserved — the wireframe only
        // overlays a dashed outline + label and substitutes leaf content.
        if ($wireframe) {
            $ui = WireframeRenderer::transform($ui);
            $t->mark('wireframe');
        }

        // Render the optional loader overlay so the DOM tree is complete.
        $loaderVNode = $entry->getLoader();
        $loaderDom = $loaderVNode?->render($state, null, RenderPhase::Initial);
        $t->mark('render loader');

        // Initial render: bind every listener in the tree (each event's
        // add() queues its client wiring) before the JS dump is drained.
        $ui->bindEvents();
        $t->mark('bind events');

        $stateJs = $state->getClientJs();

        // Collect custom JS / inline CSS registered by components.
        $inlineScripts = implode("\n", $entry->getScriptsInline());
        $inlineStyles = implode("\n", $entry->getStylesInline());

        $stateHash = self::computeStateHash($state);
        $styleHash = self::styleVersion();
        $t->mark('compute hash');

        // Debug panel → inline script for initial render. Construct AFTER
        // timings so far so they appear in the debug output.
        (new DebugPanel($ui, $state, $t))->emit();
        $debugJs = self::callsToJs(Js::drain());

        $bootJs = 'window.__BRICK_HASH=' . json_encode($stateHash) . ';'
                . 'window.__BRICK_DEV=' . json_encode($isDev) . ';'
                // Read by brick_debug.js to gate its wireframe-only behaviour
                // (hover highlight + read-only click swallow) on this render.
                . 'window.__BRICK_WIREFRAME=' . json_encode($wireframe) . ';'
                . 'window.__BRICK_ENV=' . json_encode(self::phpEnv()) . ';'
                // Replaced at echo time with the real timings (below), so they
                // cover the whole request including toHtml().
                . 'window.__BRICK_CODE_MS=__BRICK_CODE_MS__;'
                . 'window.__BRICK_PHP_MS=__BRICK_PHP_MS__;'
                . 'window.__BRICK_SECTIONS=__BRICK_SECTIONS__;';
        if ($isDev) {
            $tpl = self::editorUrlTemplate();
            if ($tpl !== '') {
                $bootJs .= 'window.__BRICK_EDITOR_URL=' . json_encode($tpl) . ';';
            }
        }

        $head = (new TagDomNode('head'))
            ->content(
                (new TagDomNode('meta'))->attr('charset', 'UTF-8'),
                (new TagDomNode('meta'))->attr('name', 'viewport')->attr('content', 'width=device-width, initial-scale=1.0'),
                (new TagDomNode('title'))->rawContent(htmlspecialchars($entry->title())),
                // Declared so browsers don't blindly probe /favicon.ico on
                // every page (which would rewrite to index.php and force a
                // full app render for an icon poll). Served as a static file
                // from /favicon.ico via Apache's `!-f` guard.
                (new TagDomNode('link'))->attr('rel', 'icon')->attr('type', 'image/x-icon')->attr('href', '/favicon.ico'),
                (new TagDomNode('script'))->rawContent($bootJs),
                // /style.css bundles preflight + the extracted utility rules
                // (in that order, so application rules win).
                (new TagDomNode('link'))->attr('rel', 'stylesheet')->attr('href', '/style.css?h=' . $styleHash),
            );

        // User-registered external stylesheets — placed after /style.css so
        // their rules can override framework defaults.
        foreach ($entry->getStyles() as $href) {
            $head->content((new TagDomNode('link'))->attr('rel', 'stylesheet')->attr('href', $href));
        }
        // User inline styles after externals, so they override.
        if ($inlineStyles !== '') {
            $head->content((new TagDomNode('style'))->rawContent($inlineStyles));
        }

        // Framework runtime first so Brick.* is available to user scripts.
        $head->content((new TagDomNode('script'))->attr('src', '/brick.js')->rawContent(''));
        foreach ($entry->getScripts() as $script) {
            $tag = (new TagDomNode('script'))->attr('src', $script['src']);
            if ($script['defer']) {
                $tag->attr('defer', 'defer');
            }
            $head->content($tag->rawContent(''));
        }
        if ($inlineScripts !== '') {
            $head->content((new TagDomNode('script'))->rawContent($inlineScripts));
        }

        if ($wireframe) {
            $head->content((new TagDomNode('style'))->rawContent(self::WIREFRAME_CSS));
        }

        if ($stateJs !== null) {
            $head->content((new TagDomNode('script'))->rawContent($stateJs));

            // After a full-page (client=false) navigation, the server already
            // applied the event to client-side state — push it into storage so
            // the localStorage/sessionStorage manager reflects the change
            // instead of reloading the pre-event value. Runs right after the
            // handler is registered; server-side managers return null here and
            // emit nothing.
            if ($pushClientState) {
                $clientState = $state->getClientState();
                if ($clientState !== null) {
                    $head->content((new TagDomNode('script'))->rawContent(
                        'Brick.setAll(' . json_encode($clientState) . ');'
                    ));
                }
            }
        }

        $body = (new TagDomNode('body'))
            ->attr('style', 'margin: 0; font-family: system-ui, -apple-system, sans-serif;')
            ->content($ui);

        // Optional loader overlay — sibling of the App root so it isn't part
        // of the diff tree. Hidden by default; the frontend toggles its
        // display while a request is in flight.
        if ($loaderDom !== null) {
            $loaderWrapper = (new TagDomNode('div'))
                ->attr('data-brick-loader', '')
                ->attr('style', 'display:none')
                ->content($loaderDom);
            $body->content($loaderWrapper);
        }

        $body->content((new TagDomNode('script'))->rawContent($debugJs));

        // Debug runtime (served statically as /brick_debug.js): the ctrl+click
        // open-in-editor inspector + the "w" wireframe-toggle keybind, plus the
        // wireframe hover-highlight / read-only click swallow. Loaded on every
        // dev page; the wireframe-only behaviour self-gates on
        // window.__BRICK_WIREFRAME (set in bootJs above).
        if ($isDev) {
            $body->content((new TagDomNode('script'))->attr('src', '/brick_debug.js')->rawContent(''));
        }

        $document = (new TagDomNode('html'))
            ->attr('lang', 'en')
            ->content($head, $body);

        $html = '<!DOCTYPE html>' . $document->toHtml();
        $t->mark('output');

        echo str_replace(
            ['__BRICK_CODE_MS__', '__BRICK_PHP_MS__', '__BRICK_SECTIONS__'],
            [(string) self::codeMs(), (string) self::phpMs(), json_encode($t->all())],
            $html,
        );
    }

    /**
     * Concatenate queued JS statement strings into inline JavaScript
     * for the initial GET response. Just joins with `;` — callers
     * already build each statement as a complete expression.
     *
     * @param string[] $calls
     */
    private static function callsToJs(array $calls): string
    {
        return $calls === [] ? '' : implode(';', $calls) . ';';
    }
}
