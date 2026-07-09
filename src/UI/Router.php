<?php

namespace BrickPHP\UI;

use BrickPHP\Js\History;
use BrickPHP\State\StateManager;
use BrickPHP\VNode\App;
use BrickPHP\VNode\Component;
use BrickPHP\VNode\RenderPhase;
use BrickPHP\VNode\VNode;

/**
 * Static facade plus router Component.
 *
 *   Router::router()                         start a builder
 *       ->register(HomeRoute::class, fn(HomeRoute $r)        => new HomePage())
 *       ->register(ArticleRoute::class, fn(ArticleRoute $r)  => new Article($r->slug))
 *       ->fallback(new NotFoundPage())
 *
 *   Router::link(new ArticleRoute('hello'))        SPA-handled <a href>
 *   Router::link(new ExternalRoute(...), external: true)   browser-handled <a>
 *
 *   Router::navigate(new ArticleRoute('hello'))    call from any event handler
 *   Router::navigate('/some-path')                 string also accepted
 *
 * On render the Router walks its registered route classes in order, asks each
 * `handle($uri)` to claim the URL, and invokes the matching handler with the
 * parsed route instance.
 */
class Router extends Component
{
    private static ?string $navigateUri = null;
    private string $uri = '/';

    /** @var array<class-string<BaseRoute>, callable(BaseRoute): mixed> */
    private array $routes = [];
    private ?VNode $fallback = null;

    // ============================================================
    // Static facade
    // ============================================================

    /**
     * Start a router builder. Chain ->register(...) calls and (optionally)
     * ->fallback(...) on the returned instance, then embed it in your tree.
     */
    public static function router(): static
    {
        return new static();
    }

    /**
     * Build an anchor element for a route.
     *
     *   Router::link(new ArticleRoute('hi'))->content('Read more');
     *
     * With external=false (default) the click is intercepted client-side and
     * the navigation is routed through Brick — no full-page reload. With
     * external=true the anchor is rendered as-is and the browser handles it.
     */
    public static function link(BaseRoute $route, bool $external = false): Link
    {
        return new Link($route, external: $external);
    }

    /**
     * Register the Router's client-side assets on the given App. Call from
     * your App::registerAssets() override:
     *
     *   protected function registerAssets(App $app): void {
     *       Router::registerAssets($app);
     *   }
     *
     * The script (served statically as /router.js) wires the browser's
     * History API to Brick: any back/forward navigation fires Brick.refresh(),
     * which POSTs an empty event to the new URL. The server renders the route
     * matched by the new REQUEST_URI and diff-patches the page in place. It
     * also manages scroll restoration across SPA navigation and reloads.
     *
     * Only popstate is hooked — pushState navigations are already issued
     * by the framework with patches in the same response, so they don't
     * need an extra refresh.
     */
    public static function registerAssets(App $app): void
    {
        $app->addScript('/router.js?v=' . \BrickPHP\Brick::assetVersion('router.js'));
    }

    /**
     * Push a URL into history and queue a re-render of the active Router. May
     * be called with a BaseRoute (typed, preferred) or a raw URL string.
     */
    public static function navigate(BaseRoute|string $target): void
    {
        $url = $target instanceof BaseRoute ? $target->toUrl() : $target;
        self::$navigateUri = $url;
        History::pushState(null, '', $url);
    }

    // ============================================================
    // Builder
    // ============================================================

    /**
     * Register a route class + handler. The handler receives a parsed
     * instance of the route class when its `handle()` claims the URL.
     *
     * @template T of BaseRoute
     * @param class-string<T> $routeClass
     * @param callable(T): mixed $handler  Returns a VNode/UIElement/string.
     */
    public function register(string $routeClass, callable $handler): static
    {
        $this->routes[$routeClass] = $handler;
        return $this;
    }

    /**
     * Optional fallback when no registered route claims the URI.
     */
    public function fallback(VNode|string $content): static
    {
        $this->fallback = is_string($content) ? UI::text($content) : $content;
        return $this;
    }

    // ============================================================
    // Component lifecycle
    // ============================================================

    public function render(StateManager $state, ?VNode $parent = null, RenderPhase $phase = RenderPhase::Initial): DomNode
    {
        // Read REQUEST_URI on every render (POSTs go to window.location.href,
        // so this is the source of truth for "where the browser is"). An
        // in-flight navigation from a click handler overrides it via
        // restored() below.
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        $this->uri = parse_url($path, PHP_URL_PATH) ?? '/';

        return parent::render($state, $parent, $phase);
    }

    protected function restored(): void
    {
        if (self::$navigateUri !== null) {
            $this->uri = self::$navigateUri;
            self::$navigateUri = null;
        }
    }

    protected function build(): VNode
    {
        foreach ($this->routes as $routeClass => $handler) {
            $route = $routeClass::handle($this->uri);
            if ($route !== null) {
                return self::normalize($handler($route));
            }
        }

        return $this->fallback ?? UI::text('404 — Not Found');
    }

    private static function normalize(mixed $value): VNode
    {
        if ($value instanceof VNode) {
            return $value;
        }
        return UI::text((string)$value);
    }
}
