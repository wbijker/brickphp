<?php

namespace BrickPHP\VNode;

use BrickPHP\Error\DefaultErrorPage;
use BrickPHP\Error\ErrorInfo;
use BrickPHP\State\GlobalStateManager;
use BrickPHP\State\StateManager;
use BrickPHP\UI\DomNode;
use BrickPHP\UI\UIElement;

abstract class App extends Component
{
    /** @var string[] Inline <script> bodies registered by components */
    private array $inlineScripts = [];

    /** @var string[] Inline <style> bodies registered by components */
    private array $inlineStyles = [];

    /** @var array<array{src: string, defer: bool}> External <script src=...> entries registered by components */
    private array $scripts = [];

    /** @var string[] External <link rel="stylesheet" href=...> URLs registered by components */
    private array $styles = [];

    /** Bound once per app instance, on the first render — see {@see registerGlobalState()}. */
    private ?GlobalStateManager $globalState = null;

    abstract public function title(): string;

    abstract protected function view(): VNode;

    /**
     * Override to register custom assets on this App.
     *
     * Called once by the framework on the initial GET, before the first
     * render. Four flavors:
     *
     *   $app->addStyle('https://cdn.example.com/lib.css');     // external <link>
     *   $app->addScript('https://cdn.example.com/lib.js');     // external <script src>
     *   $app->addStyleInline('.brand { color: tomato }');      // inline <style>
     *   $app->addScriptInline('window.MyApp = { ready: 1 };'); // inline <script>
     *
     * Not called on POST event responses — assets persist from the initial
     * page load, so register everything you might need up front.
     */
    protected function registerAssets(App $app): void
    {
    }

    /**
     * Framework entry point — runs the registerAssets() hook with this App
     * as both the receiver and the argument. Called by Brick::run() on the
     * initial GET before rendering. Not part of the public override surface
     * — subclasses override registerAssets() instead.
     */
    public function runRegisterAssets(): void
    {
        $this->registerAssets($this);
    }

    /**
     * Optional overlay element shown while a server request is in flight.
     *
     * Rendered once on the initial page load as a sibling of the main app
     * root, hidden with `display: none`. The frontend toggles it to
     * `display: block` when a request is pending and back to `display: none`
     * once the response has been applied. Return `null` to disable.
     */
    protected function loader(): ?VNode
    {
        return null;
    }

    /**
     * Framework runtime accessor for the loader hook.
     */
    public function getLoader(): ?VNode
    {
        return $this->loader();
    }

    /**
     * Return the state manager used by this app.
     */
    abstract public function state(): StateManager;

    /** Register an external stylesheet by URL — emitted as <link rel="stylesheet" href="..."> in <head>. */
    public function addStyle(string $href): void
    {
        $this->styles[] = $href;
    }

    /**
     * Register an external script by URL — emitted as `<script src="...">`
     * in `<head>`.
     *
     * `$defer` (default `false`) toggles the `defer` attribute on the tag.
     * A deferred script:
     *   - is fetched in parallel with HTML parsing (never blocks the parser),
     *   - executes after the document has finished parsing, just before
     *     `DOMContentLoaded` fires,
     *   - and preserves source order relative to other deferred scripts.
     *
     * Use `defer=true` for scripts that need the DOM to exist but don't
     * have to run immediately; leave it `false` for scripts that other
     * code (inline scripts, later head scripts) relies on synchronously.
     */
    public function addScript(string $src, bool $defer = false): void
    {
        $this->scripts[] = ['src' => $src, 'defer' => $defer];
    }

    /** Register an inline CSS snippet — concatenated and emitted as a <style> block in <head>. */
    public function addStyleInline(string $css): void
    {
        $this->inlineStyles[] = $css;
    }

    /** Register an inline JS snippet — concatenated and emitted as a <script> block in <head>. */
    public function addScriptInline(string $js): void
    {
        $this->inlineScripts[] = $js;
    }

    /** @return string[] */
    public function getStyles(): array
    {
        return $this->styles;
    }

    /** @return array<array{src: string, defer: bool}> */
    public function getScripts(): array
    {
        return $this->scripts;
    }

    /** @return string[] */
    public function getStylesInline(): array
    {
        return $this->inlineStyles;
    }

    /** @return string[] */
    public function getScriptsInline(): array
    {
        return $this->inlineScripts;
    }

    protected function build(): VNode
    {
        return $this->view();
    }

    /**
     * Declare state that belongs to the application rather than to a component,
     * by binding static properties:
     *
     *   protected function registerGlobalState(GlobalStateManager $manager): void
     *   {
     *       $manager->useState(Session::$userId);
     *       $manager->useState(Session::$name);
     *   }
     *
     * Every component in every tree then reads `Session::$name` directly — no
     * prop to thread down, no key to spell, and the type is the property's own.
     * The manager carries the same {@see GlobalStateManager::useState()} the
     * components use, and the values are restored before the first render and
     * saved with the rest of the state at the end of the request.
     *
     * See {@see GlobalStateManager} for the two rules that come with it:
     * registration order is the storage contract, and every bound property
     * needs a default.
     */
    protected function registerGlobalState(GlobalStateManager $manager): void
    {
    }

    /**
     * Bind and restore the app's global state, once per instance. Called at the
     * top of render() so every phase — the initial GET, the OLD replay and the
     * NEW render of a patch — starts from the stored values.
     */
    public function render(StateManager $state, ?VNode $parent = null, RenderPhase $phase = RenderPhase::Initial): DomNode
    {
        if ($this->globalState === null) {
            $this->globalState = new GlobalStateManager($state);
            $this->registerGlobalState($this->globalState);
            $this->globalState->restore();
        }

        return parent::render($state, $parent, $phase);
    }

    /**
     * The app's own state, plus the globals. Rides the existing save path:
     * handleGet finalizes the app directly, and a POST reaches it through
     * Component::finalizeAll(), so a handler's changes to a global are
     * persisted wherever a component's own would be.
     */
    public function finalize(StateManager $state): void
    {
        parent::finalize($state);
        $this->globalState?->save();
    }

    /**
     * Render an error page when an unrecoverable error occurs during a
     * request. Override to customize the look or to log/notify before
     * returning a view. The default returns a centered modern card with
     * the type, message, location, and (if available) stack trace.
     */
    public function error(ErrorInfo $info): VNode
    {
        return new DefaultErrorPage($info);
    }
}
