<?php

namespace BrickPHP\UI;

use BrickPHP\Events\AnimationEvent;
use BrickPHP\Events\ClipboardEvent;
use BrickPHP\Events\DragEvent;
use BrickPHP\Events\FileEvent;
use BrickPHP\Events\InputEvent;
use BrickPHP\Events\KeyboardEvent;
use BrickPHP\Events\MouseEvent;
use BrickPHP\Events\PointerEvent;
use BrickPHP\Events\ResizeEvent;
use BrickPHP\Events\ScrollEvent;
use BrickPHP\Events\TouchEvent;
use BrickPHP\Events\TransitionEvent;
use BrickPHP\Events\WheelEvent;
use BrickPHP\Events\Events;
use BrickPHP\Events\EventPhase;
use BrickPHP\Events\EventRegistration;
use BrickPHP\Events\Key;
use BrickPHP\State\StateManager;
use BrickPHP\VNode\Component;
use BrickPHP\VNode\Node;
use BrickPHP\VNode\RenderPhase;
use BrickPHP\VNode\VNode;

/**
 * Base class for all UI elements.
 * Extends Node with additional styling and layout capabilities.
 */
class UIElement extends Node
{
    /** @var Component|null The component that owns this element's events */
    protected ?Component $eventOwner = null;

    /**
     * When true, every UIElement::__construct walks the call stack to find
     * the first non-framework frame and stamps its file:line onto the DOM
     * node. Flipped on by Brick::run when isDevelopment is true, so
     * production renders skip the backtrace entirely.
     */
    public static bool $captureSource = false;

    /**
     * Server-side root that path-mapping strips when rewriting captured file
     * paths into host paths for the editor link. Set by Brick::run from the
     * auto-detected project root.
     */
    public static ?string $sourceRoot = null;

    /**
     * Host-side root the editor link uses. Combined with $sourceRoot to
     * remap container/VM paths back to the dev's local checkout. Defaults
     * to $sourceRoot (no remap) when config.editor.host_root is absent.
     */
    public static ?string $hostRoot = null;

    public function __construct(string $tag = 'div')
    {
        parent::__construct(new TagDomNode($tag));

        // Only stamp wireframe metadata in dev mode. The capture flag is
        // flipped by Brick::run() from isDevelopment(); production renders
        // produce zero data-wf-* output and skip the backtrace entirely.
        if (self::$captureSource) {
            $cls = static::class;
            $short = ($pos = strrpos($cls, '\\')) !== false ? substr($cls, $pos + 1) : $cls;
            if ($short !== 'UIElement' && $short !== 'UIElementContent') {
                $this->dom()->wireframeLabel = strtolower($short);
            }
            $this->captureCallSite();
        }
    }

    /**
     * Find the first frame outside brickphp/src/ — that's the user code that
     * called UI::row() / UI::text() / etc. Cheap (debug_backtrace with
     * IGNORE_ARGS), but still only runs when the static capture flag is on.
     */
    private function captureCallSite(): void
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($bt as $frame) {
            $file = $frame['file'] ?? '';
            if ($file === '') continue;
            if (str_contains($file, '/brickphp/src/') || str_contains($file, '\\brickphp\\src\\')) continue;
            $this->dom()->wireframeFile = self::mapHostPath($file);
            $this->dom()->wireframeLine = $frame['line'] ?? 0;
            return;
        }
    }

    /**
     * Rewrite a server-captured absolute path so it points to the host
     * checkout (e.g. /var/www/foo.php → /Users/me/projects/x/foo.php). The
     * editor jump-link won't resolve unless the path the OS receives exists
     * on the host filesystem. No-op when the path doesn't sit under the
     * configured server root, or when host_root wasn't configured.
     */
    public static function mapHostPath(string $file): string
    {
        if (self::$sourceRoot === null || self::$hostRoot === null) return $file;
        if (self::$sourceRoot === self::$hostRoot) return $file;
        if (!str_starts_with($file, self::$sourceRoot)) return $file;
        return self::$hostRoot . substr($file, strlen(self::$sourceRoot));
    }

    /**
     * Underlying DOM node. Public so framework extensions (e.g. the
     * Leaflet wrapper) can attach custom-name event handlers via
     * `$el->dom()->on('leaflet:click', $cb)` — user code should use the
     * typed `onClick` / `onChange` / … wrappers instead.
     */
    public function dom(): DomNode
    {
        return $this->domNode;
    }

    /**
     * Apply element-specific attributes to the underlying DOM node.
     * Subclasses override to write HTML attributes (placeholder, type, etc.)
     * derived from setter-stored state. Called before render() and build().
     */
    protected function applyAttributes(): void
    {
    }

    /**
     * Build the DomNode for this element (without VNode lifecycle).
     * Subclasses override to provide custom DOM building.
     */
    public function build(): DomNode
    {
        $this->applyAttributes();
        $this->applyInvalidations();
        return $this->dom();
    }

    /**
     * Render this UI element to a DOM node.
     * @param StateManager $state The state manager
     * @param VNode|null $parent The parent VNode
     * @param RenderPhase $phase The render phase (Initial or Patch)
     */
    public function render(StateManager $state, ?VNode $parent = null, RenderPhase $phase = RenderPhase::Initial): DomNode
    {
        $this->parent = $parent;
        if (empty($this->path)) {
            $this->path = $parent?->getPath() ?? [];
        }

        // Frozen during a diff cycle: skip the whole render. Both OLD (DiffOld)
        // and NEW (Patch) get an empty-but-frozen TagDomNode; compare() then
        // short-circuits and emits no patches. The frontend DOM keeps whatever
        // was rendered on the initial GET. First-page render still runs in
        // full because the HTML payload needs the real subtree.
        if ($this->dom()->isFrozen() && $phase !== RenderPhase::Initial) {
            return $parent === null ? $this->dom()->assignPaths($this->path) : $this->dom();
        }

        $this->applyAttributes();

        $this->eventOwner = $this->findOwningComponent($parent);
        if ($this->eventOwner !== null) {
            $this->dom()->setEventOwner($this->eventOwner);
        }

        $this->applyInvalidations();

        // Paths are assigned for the whole tree in a single pass at the root
        // (parent === null). Nested renders skip it, so assignment is O(n)
        // rather than O(n²) (each node re-walking its subtree).
        return $parent === null ? $this->dom()->assignPaths($this->path) : $this->dom();
    }

    /**
     * Find the nearest Component ancestor.
     */
    protected function findOwningComponent(?VNode $node): ?Component
    {
        // Nearest Component ancestor, in O(1): render is top-down, so the
        // parent already resolved its own owner. A Component parent is the
        // owner; a UIElement parent's eventOwner is the nearest Component
        // above it. Avoids walking the whole parent chain per node (O(n·depth)).
        if ($node instanceof Component) {
            return $node;
        }
        if ($node instanceof UIElement) {
            return $node->eventOwner;
        }
        return null;
    }

    // ============================================================
    // Delegated methods from TagDomNode
    // ============================================================

    protected function addStyle(string $class, array $css): void
    {
        $this->dom()->style($class, $css);
    }

    public function key(string $key): static
    {
        $this->dom()->key($key);
        return $this;
    }

    public function attr(string $name, string $value): static
    {
        $this->dom()->attr($name, $value);
        return $this;
    }

    /**
     * Force this element — and recursively every attribute, class, text node,
     * and child below it — to be patched on every diff. Use for trees whose
     * server-side OLD/NEW renders happen in the same instant and agree on
     * values (e.g. clock hands) while the frontend DOM holds stale values
     * from earlier renders.
     */
    public function invalidate(bool $on = true): static
    {
        $this->dom()->setInvalidated($on);
        return $this;
    }

    /**
     * Force-patch a single attribute on this element, regardless of how it
     * was set (via attr(), via a styling helper like color() / background()
     * that ultimately writes the `class` attribute, etc.). The rest of the
     * element diffs normally.
     *
     *   $el->color(Color::red())->invalidateAttr('class')
     *   $el->attr('data-tick', $now)->invalidateAttr('data-tick')
     */
    public function invalidateAttr(string $name): static
    {
        $this->dom()->markInvalidatedAttr($name);
        return $this;
    }

    /**
     * Force-patch every text node directly inside this element on every
     * diff. Element children are untouched — for those use ->invalidate()
     * on the child itself, or ->invalidate() recursively on this element.
     *
     *   UI::text(date('H:i:s'))->invalidateText()
     *   $el->content('label: ' . $value)->invalidateText()
     *
     * String children added via content() are only materialised into
     * TextDomNodes during build()/render(), so the marking is deferred to
     * the applyInvalidations() hook called after that step.
     */
    public function invalidateText(): static
    {
        $this->invalidateTextOnRender = true;
        $this->applyInvalidations();
        return $this;
    }

    /** @var bool Whether direct text children should be force-patched on each render */
    protected bool $invalidateTextOnRender = false;

    /**
     * Idempotent post-children hook. Called once children are present on
     * the underlying dom node so flags set before children materialise
     * still take effect.
     */
    protected function applyInvalidations(): void
    {
        if (!$this->invalidateTextOnRender) {
            return;
        }
        foreach ($this->dom()->getChildren() as $child) {
            if ($child instanceof TextDomNode) {
                $child->setInvalidated(true);
            }
        }
    }

    /**
     * Opposite of invalidate(): the diff walks past this element entirely.
     * No attributes, classes, text, or children below it are compared, and
     * no patches are emitted. The frontend DOM keeps whatever it had — use
     * for static subtrees (headers, logos) to skip traversal cost.
     */
    public function frozen(bool $on = true): static
    {
        $this->dom()->setFrozen($on);
        return $this;
    }

    public function attrs(array $attributes): static
    {
        $this->dom()->attrs($attributes);
        return $this;
    }

    public function class(string ...$classes): static
    {
        $this->dom()->class(...$classes);
        return $this;
    }

    public function style(string $className, array $css): static
    {
        $this->dom()->style($className, $css);
        return $this;
    }
    // ============================================================
    // Mouse Events
    // ============================================================

    /**
     * Register a custom event. The EventRegistration owns the event name and
     * the client wiring (e.g. Leaflet's map.on) — this just binds the server
     * $callback to the registration's event so a dispatch resolves to it.
     */
    public function customEvent(callable $callback, EventRegistration $registration): static
    {
        $this->dom()->on($registration->eventName(), $callback, null, $registration);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onClick(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::CLICK, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onDblClick(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::DBL_CLICK, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onMouseDown(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::MOUSE_DOWN, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onMouseUp(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::MOUSE_UP, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onMouseOver(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::MOUSE_OVER, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onMouseOut(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::MOUSE_OUT, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onMouseEnter(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::MOUSE_ENTER, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onMouseLeave(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::MOUSE_LEAVE, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onMouseMove(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::MOUSE_MOVE, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(MouseEvent): void $callback */
    public function onContextMenu(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::CONTEXT_MENU, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Keyboard Events
    // ============================================================

    /**
     * @param callable(KeyboardEvent): void $callback
     * @param array<Key|string> $keys When non-empty, the handler fires — and
     *   only then round-trips to the server — solely for these keys. The match
     *   is done on the client against `KeyboardEvent.key`, so every other key
     *   is ignored without a server request. Pass {@see Key} cases (e.g.
     *   `[Key::Enter, Key::Escape]`) or raw `KeyboardEvent.key` strings.
     */
    public function onKeyDown(callable $callback, array $keys = [], EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::KEY_DOWN, $callback, null, null, $phase, $client, self::keyValues($keys));
        return $this;
    }

    /**
     * @param callable(KeyboardEvent): void $callback
     * @param array<Key|string> $keys See {@see onKeyDown()} — client-side key filter.
     */
    public function onKeyUp(callable $callback, array $keys = [], EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::KEY_UP, $callback, null, null, $phase, $client, self::keyValues($keys));
        return $this;
    }

    /**
     * Normalise a key list to the `KeyboardEvent.key` strings the client filter
     * compares against. Accepts {@see Key} cases or raw strings interchangeably.
     *
     * @param array<Key|string> $keys
     * @return string[]
     */
    private static function keyValues(array $keys): array
    {
        return array_values(array_map(
            static fn(Key|string $k): string => $k instanceof Key ? $k->value : $k,
            $keys,
        ));
    }

    // ============================================================
    // Form / Input Events
    // ============================================================

    /**
     * @param callable(InputEvent): void $callback
     * @param array<Key|string> $keys Client-side key filter (see {@see onKeyDown()}).
     *   Note a `change` event carries no key, so the filter only has an effect
     *   when the underlying event provides one — for true key filtering use
     *   onKeyDown()/onKeyUp().
     */
    public function onChange(callable $callback, array $keys = [], EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::CHANGE, $callback, null, null, $phase, $client, self::keyValues($keys));
        return $this;
    }

    /** @param callable(InputEvent): void $callback */
    public function onInput(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::INPUT, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(): void $callback */
    public function onSubmit(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::SUBMIT, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(): void $callback */
    public function onReset(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::RESET, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(): void $callback */
    public function onFocus(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::FOCUS, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(): void $callback */
    public function onBlur(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::BLUR, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(): void $callback */
    public function onFocusIn(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::FOCUS_IN, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(): void $callback */
    public function onFocusOut(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::FOCUS_OUT, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(InputEvent): void $callback */
    public function onSelect(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::SELECT, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(InputEvent): void $callback */
    public function onInvalid(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::INVALID, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(FileEvent): void $callback */
    public function onUpload(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::UPLOAD, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Touch Events
    // ============================================================

    /** @param callable(TouchEvent): void $callback */
    public function onTouchStart(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::TOUCH_START, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(TouchEvent): void $callback */
    public function onTouchEnd(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::TOUCH_END, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(TouchEvent): void $callback */
    public function onTouchMove(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::TOUCH_MOVE, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(TouchEvent): void $callback */
    public function onTouchCancel(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::TOUCH_CANCEL, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Pointer Events
    // ============================================================

    /** @param callable(PointerEvent): void $callback */
    public function onPointerDown(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::POINTER_DOWN, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(PointerEvent): void $callback */
    public function onPointerUp(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::POINTER_UP, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(PointerEvent): void $callback */
    public function onPointerMove(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::POINTER_MOVE, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(PointerEvent): void $callback */
    public function onPointerOver(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::POINTER_OVER, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(PointerEvent): void $callback */
    public function onPointerOut(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::POINTER_OUT, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(PointerEvent): void $callback */
    public function onPointerEnter(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::POINTER_ENTER, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(PointerEvent): void $callback */
    public function onPointerLeave(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::POINTER_LEAVE, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(PointerEvent): void $callback */
    public function onPointerCancel(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::POINTER_CANCEL, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Drag & Drop Events
    // ============================================================

    /** @param callable(DragEvent): void $callback */
    public function onDragStart(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::DRAG_START, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(DragEvent): void $callback */
    public function onDrag(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::DRAG, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(DragEvent): void $callback */
    public function onDragEnd(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::DRAG_END, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(DragEvent): void $callback */
    public function onDragEnter(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::DRAG_ENTER, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(DragEvent): void $callback */
    public function onDragLeave(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::DRAG_LEAVE, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(DragEvent): void $callback */
    public function onDragOver(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::DRAG_OVER, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(DragEvent): void $callback */
    public function onDrop(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::DROP, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Clipboard Events
    // ============================================================

    /** @param callable(ClipboardEvent): void $callback */
    public function onCopy(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::COPY, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(ClipboardEvent): void $callback */
    public function onCut(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::CUT, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(ClipboardEvent): void $callback */
    public function onPaste(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::PASTE, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Scroll & Wheel Events
    // ============================================================

    /** @param callable(ScrollEvent): void $callback */
    public function onScroll(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::SCROLL, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(WheelEvent): void $callback */
    public function onWheel(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::WHEEL, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Transition & Animation Events
    // ============================================================

    /** @param callable(TransitionEvent): void $callback */
    public function onTransitionEnd(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::TRANSITION_END, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(TransitionEvent): void $callback */
    public function onTransitionStart(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::TRANSITION_START, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(AnimationEvent): void $callback */
    public function onAnimationEnd(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::ANIMATION_END, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(AnimationEvent): void $callback */
    public function onAnimationStart(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::ANIMATION_START, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(AnimationEvent): void $callback */
    public function onAnimationIteration(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::ANIMATION_ITERATION, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Misc Events
    // ============================================================

    /** @param callable(): void $callback */
    public function onLoad(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::LOAD, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(): void $callback */
    public function onError(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::ERROR, $callback, null, null, $phase, $client);
        return $this;
    }

    /** @param callable(ResizeEvent): void $callback */
    public function onResize(callable $callback, EventPhase $phase = EventPhase::Bubble, bool $client = true): static
    {
        $this->dom()->on(Events::RESIZE, $callback, null, null, $phase, $client);
        return $this;
    }

    // ============================================================
    // Background
    // ============================================================

    public function background(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $color->withContext('bg'), ['background-color' => $color->getValue()]);
        return $this;
    }

    // ============================================================
    // Text Color
    // ============================================================

    public function color(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $color->withContext('text'), ['color' => $color->getValue()]);
        return $this;
    }

    // ============================================================
    // Border
    // ============================================================

    /**
     * Border-width setter — single int = all 4 sides (CSS `border-width`);
     * named args set per-axis / per-side widths in one call.
     *
     *   ->bordered()                                        // 1px all sides
     *   ->bordered(2)                                        // 2px all sides
     *   ->bordered(2, Pseudo::md())                          // 2px all, at md
     *   ->bordered(x: 1, y: 2)                               // l+r = 1, t+b = 2
     *   ->bordered(top: 1, bottom: 4)                        // per-side
     */
    public function bordered(
        ?int $width = null,
        ?Pseudo $pseudo = null,
        ?int $x = null,
        ?int $y = null,
        ?int $top = null,
        ?int $right = null,
        ?int $bottom = null,
        ?int $left = null,
    ): static {
        $prefix = $pseudo?->prefix() ?? '';

        // Treat zero positional args as "1px all sides" (the original default).
        $allDefault = $width === null
            && $x === null && $y === null
            && $top === null && $right === null && $bottom === null && $left === null;
        if ($allDefault) {
            $width = 1;
        }

        if ($width !== null) {
            $class = $width === 1 ? 'border' : 'border-' . $width;
            $this->addStyle($prefix . $class, ['border-width' => $width . 'px', 'border-style' => 'solid']);
        }
        if ($x !== null) {
            $cls = $x === 1 ? 'border-x' : 'border-x-' . $x;
            $this->addStyle($prefix . $cls, ['border-left-width' => $x . 'px', 'border-right-width' => $x . 'px', 'border-left-style' => 'solid', 'border-right-style' => 'solid']);
        }
        if ($y !== null) {
            $cls = $y === 1 ? 'border-y' : 'border-y-' . $y;
            $this->addStyle($prefix . $cls, ['border-top-width' => $y . 'px', 'border-bottom-width' => $y . 'px', 'border-top-style' => 'solid', 'border-bottom-style' => 'solid']);
        }
        if ($top !== null) {
            $this->addStyle($prefix . 'border-t-' . $top, ['border-top-width' => $top . 'px', 'border-top-style' => 'solid']);
        }
        if ($right !== null) {
            $this->addStyle($prefix . 'border-r-' . $right, ['border-right-width' => $right . 'px', 'border-right-style' => 'solid']);
        }
        if ($bottom !== null) {
            $this->addStyle($prefix . 'border-b-' . $bottom, ['border-bottom-width' => $bottom . 'px', 'border-bottom-style' => 'solid']);
        }
        if ($left !== null) {
            $this->addStyle($prefix . 'border-l-' . $left, ['border-left-width' => $left . 'px', 'border-left-style' => 'solid']);
        }
        return $this;
    }

    public function borderColor(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $color->withContext('border'), ['border-color' => $color->getValue()]);
        return $this;
    }

    public function dashed(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'border-dashed', ['border-style' => 'dashed']);
        return $this;
    }

    public function dotted(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'border-dotted', ['border-style' => 'dotted']);
        return $this;
    }

    public function borderTop(int $width = 1, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'border-t-' . $width, ['border-top-width' => $width . 'px', 'border-top-style' => 'solid']);
        return $this;
    }

    public function borderBottom(int $width = 1, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'border-b-' . $width, ['border-bottom-width' => $width . 'px', 'border-bottom-style' => 'solid']);
        return $this;
    }

    public function borderNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'border-none', ['border' => 'none']);
        return $this;
    }

    public function outlineNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'outline-none', ['outline' => 'none']);
        return $this;
    }

    // ============================================================
    // Sizing
    // ============================================================

    /**
     * Normalize a spacing argument: a bare int is shorthand for
     * `Unit::tick(n)` (a Tailwind spacing tick), so callers can write
     * `->padding(1)` instead of `->padding(Unit::tick(1))`. Unit and null
     * pass through unchanged. Applied at the top of every style method that
     * accepts `Unit|int`.
     */
    protected static function unit(Unit|int|null $value): ?Unit
    {
        return is_int($value) ? Unit::tick($value) : $value;
    }

    public function width(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('w'), ['width' => $value->getCssValue()]);
        return $this;
    }

    public function height(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('h'), ['height' => $value->getCssValue()]);
        return $this;
    }

    public function size(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $css = $value->getCssValue();
        $this->addStyle($prefix . $value->withContext('w'), ['width' => $css]);
        $this->addStyle($prefix . $value->withContext('h'), ['height' => $css]);
        return $this;
    }

    public function minWidth(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('min-w'), ['min-width' => $value->getCssValue()]);
        return $this;
    }

    public function maxWidth(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('max-w'), ['max-width' => $value->getCssValue()]);
        return $this;
    }

    public function minHeight(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('min-h'), ['min-height' => $value->getCssValue()]);
        return $this;
    }

    public function maxHeight(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('max-h'), ['max-height' => $value->getCssValue()]);
        return $this;
    }

    public function extend(bool $screen = false, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'w-full', ['width' => $screen ? '100vw' : '100%']);
        $this->addStyle($prefix . 'h-full', ['height' => $screen ? '100vh' : '100%']);
        return $this;
    }

    public function extendX(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'w-full', ['width' => '100%']);
        return $this;
    }

    public function extendY(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'h-full', ['height' => '100%']);
        return $this;
    }

    public function shrink(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'w-fit', ['width' => 'fit-content']);
        $this->addStyle($prefix . 'h-fit', ['height' => 'fit-content']);
        return $this;
    }

    // ============================================================
    // Spacing
    // ============================================================

    /**
     * Padding setter — accepts a single "all sides" Unit positional arg (the
     * original form) and/or per-axis / per-side named args. One call can set
     * multiple sides at one breakpoint, e.g.:
     *
     *   ->padding(Unit::rem(1))                                  // all sides
     *   ->padding(Unit::rem(1), Pseudo::md())                    // md: all sides
     *   ->padding(x: Unit::rem(1), y: Unit::rem(0.75))           // px + py in one call
     *   ->padding(x: Unit::rem(1.5), y: Unit::rem(1), pseudo: Pseudo::md())
     *   ->padding(top: Unit::rem(2), right: Unit::rem(1))        // per-side
     *
     * Mix and match — every non-null axis emits its own utility class. The
     * Pseudo (named `pseudo:`) applies to every emitted class in this call.
     */
    public function padding(
        Unit|int|null $value = null,
        ?Pseudo $pseudo = null,
        Unit|int|null $x = null,
        Unit|int|null $y = null,
        Unit|int|null $top = null,
        Unit|int|null $right = null,
        Unit|int|null $bottom = null,
        Unit|int|null $left = null,
    ): static {
        // Normalize lazily inside each guard: a single-arg call like
        // ->padding(1) does one Unit conversion, not seven.
        $prefix = $pseudo?->prefix() ?? '';
        if ($value !== null) {
            $value = self::unit($value);
            $this->addStyle($prefix . $value->withContext('p'), ['padding' => $value->getCssValue()]);
        }
        if ($x !== null) {
            $x = self::unit($x);
            $css = $x->getCssValue();
            $this->addStyle($prefix . $x->withContext('px'), ['padding-left' => $css, 'padding-right' => $css]);
        }
        if ($y !== null) {
            $y = self::unit($y);
            $css = $y->getCssValue();
            $this->addStyle($prefix . $y->withContext('py'), ['padding-top' => $css, 'padding-bottom' => $css]);
        }
        if ($top !== null) {
            $top = self::unit($top);
            $this->addStyle($prefix . $top->withContext('pt'), ['padding-top' => $top->getCssValue()]);
        }
        if ($right !== null) {
            $right = self::unit($right);
            $this->addStyle($prefix . $right->withContext('pr'), ['padding-right' => $right->getCssValue()]);
        }
        if ($bottom !== null) {
            $bottom = self::unit($bottom);
            $this->addStyle($prefix . $bottom->withContext('pb'), ['padding-bottom' => $bottom->getCssValue()]);
        }
        if ($left !== null) {
            $left = self::unit($left);
            $this->addStyle($prefix . $left->withContext('pl'), ['padding-left' => $left->getCssValue()]);
        }
        return $this;
    }

    public function paddingX(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $css = $value->getCssValue();
        $this->addStyle($prefix . $value->withContext('px'), ['padding-left' => $css, 'padding-right' => $css]);
        return $this;
    }

    public function paddingY(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $css = $value->getCssValue();
        $this->addStyle($prefix . $value->withContext('py'), ['padding-top' => $css, 'padding-bottom' => $css]);
        return $this;
    }

    public function paddingTop(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('pt'), ['padding-top' => $value->getCssValue()]);
        return $this;
    }

    /**
     * Margin setter — same multi-axis shape as padding(). See padding() for
     * usage examples; substitute m/mx/my/mt/mr/mb/ml for the utility prefixes.
     */
    public function margin(
        Unit|int|null $value = null,
        ?Pseudo $pseudo = null,
        Unit|int|null $x = null,
        Unit|int|null $y = null,
        Unit|int|null $top = null,
        Unit|int|null $right = null,
        Unit|int|null $bottom = null,
        Unit|int|null $left = null,
    ): static {
        // Normalize lazily inside each guard (see padding()).
        $prefix = $pseudo?->prefix() ?? '';
        if ($value !== null) {
            $value = self::unit($value);
            $this->addStyle($prefix . $value->withContext('m'), ['margin' => $value->getCssValue()]);
        }
        if ($x !== null) {
            $x = self::unit($x);
            $css = $x->getCssValue();
            $this->addStyle($prefix . $x->withContext('mx'), ['margin-left' => $css, 'margin-right' => $css]);
        }
        if ($y !== null) {
            $y = self::unit($y);
            $css = $y->getCssValue();
            $this->addStyle($prefix . $y->withContext('my'), ['margin-top' => $css, 'margin-bottom' => $css]);
        }
        if ($top !== null) {
            $top = self::unit($top);
            $this->addStyle($prefix . $top->withContext('mt'), ['margin-top' => $top->getCssValue()]);
        }
        if ($right !== null) {
            $right = self::unit($right);
            $this->addStyle($prefix . $right->withContext('mr'), ['margin-right' => $right->getCssValue()]);
        }
        if ($bottom !== null) {
            $bottom = self::unit($bottom);
            $this->addStyle($prefix . $bottom->withContext('mb'), ['margin-bottom' => $bottom->getCssValue()]);
        }
        if ($left !== null) {
            $left = self::unit($left);
            $this->addStyle($prefix . $left->withContext('ml'), ['margin-left' => $left->getCssValue()]);
        }
        return $this;
    }

    public function marginX(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $css = $value->getCssValue();
        $this->addStyle($prefix . $value->withContext('mx'), ['margin-left' => $css, 'margin-right' => $css]);
        return $this;
    }

    public function marginY(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $css = $value->getCssValue();
        $this->addStyle($prefix . $value->withContext('my'), ['margin-top' => $css, 'margin-bottom' => $css]);
        return $this;
    }

    // ============================================================
    // Corners
    // ============================================================

    /**
     * Border-radius setter — single positional Unit = all 4 corners; named
     * args set per-side or per-corner radii in one call.
     *
     *   ->rounded()                                            // 0.25rem all
     *   ->rounded(Unit::rem(0.5))                              // all corners
     *   ->rounded(Unit::rem(0.5), Pseudo::md())                // md: all
     *   ->rounded(top: Unit::rem(1))                           // tl + tr
     *   ->rounded(top: Unit::rem(1), bottom: Unit::none())     // per-side
     *   ->rounded(topLeft: Unit::rem(2))                       // per-corner
     */
    public function rounded(
        Unit|int|null $value = null,
        ?Pseudo $pseudo = null,
        Unit|int|null $top = null,
        Unit|int|null $right = null,
        Unit|int|null $bottom = null,
        Unit|int|null $left = null,
        Unit|int|null $topLeft = null,
        Unit|int|null $topRight = null,
        Unit|int|null $bottomLeft = null,
        Unit|int|null $bottomRight = null,
    ): static {
        $prefix = $pseudo?->prefix() ?? '';

        // Bare ->rounded() retains its original "0.25rem all" shorthand.
        // Null-ness is checked on the raw args (unit() preserves null), so we
        // normalize lazily inside each guard rather than all nine up front.
        $allDefault = $value === null
            && $top === null && $right === null && $bottom === null && $left === null
            && $topLeft === null && $topRight === null && $bottomLeft === null && $bottomRight === null;
        if ($allDefault) {
            $this->addStyle($prefix . 'rounded', ['border-radius' => '0.25rem']);
            return $this;
        }

        if ($value !== null) {
            $value = self::unit($value);
            $this->addStyle($prefix . $value->withContext('rounded'), ['border-radius' => $value->getCssValue()]);
        }
        if ($top !== null) {
            $top = self::unit($top);
            $css = $top->getCssValue();
            $this->addStyle($prefix . $top->withContext('rounded-t'), ['border-top-left-radius' => $css, 'border-top-right-radius' => $css]);
        }
        if ($right !== null) {
            $right = self::unit($right);
            $css = $right->getCssValue();
            $this->addStyle($prefix . $right->withContext('rounded-r'), ['border-top-right-radius' => $css, 'border-bottom-right-radius' => $css]);
        }
        if ($bottom !== null) {
            $bottom = self::unit($bottom);
            $css = $bottom->getCssValue();
            $this->addStyle($prefix . $bottom->withContext('rounded-b'), ['border-bottom-left-radius' => $css, 'border-bottom-right-radius' => $css]);
        }
        if ($left !== null) {
            $left = self::unit($left);
            $css = $left->getCssValue();
            $this->addStyle($prefix . $left->withContext('rounded-l'), ['border-top-left-radius' => $css, 'border-bottom-left-radius' => $css]);
        }
        if ($topLeft !== null) {
            $topLeft = self::unit($topLeft);
            $this->addStyle($prefix . $topLeft->withContext('rounded-tl'), ['border-top-left-radius' => $topLeft->getCssValue()]);
        }
        if ($topRight !== null) {
            $topRight = self::unit($topRight);
            $this->addStyle($prefix . $topRight->withContext('rounded-tr'), ['border-top-right-radius' => $topRight->getCssValue()]);
        }
        if ($bottomLeft !== null) {
            $bottomLeft = self::unit($bottomLeft);
            $this->addStyle($prefix . $bottomLeft->withContext('rounded-bl'), ['border-bottom-left-radius' => $bottomLeft->getCssValue()]);
        }
        if ($bottomRight !== null) {
            $bottomRight = self::unit($bottomRight);
            $this->addStyle($prefix . $bottomRight->withContext('rounded-br'), ['border-bottom-right-radius' => $bottomRight->getCssValue()]);
        }
        return $this;
    }

    public function roundedFull(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'rounded-full', ['border-radius' => '9999px']);
        return $this;
    }

    // ============================================================
    // Shadow
    // ============================================================

    public function shadow(Shadow $size = Shadow::Medium, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $size->toClass(), ['box-shadow' => $size->getCssValue()]);
        return $this;
    }

    // ============================================================
    // Opacity
    // ============================================================

    public function opacity(int $value, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'opacity-' . $value, ['opacity' => (string)($value / 100)]);
        return $this;
    }

    // ============================================================
    // Visibility
    // ============================================================

    public function hidden(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'hidden', ['display' => 'none']);
        return $this;
    }

    public function visible(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'visible', ['visibility' => 'visible']);
        return $this;
    }

    // ============================================================
    // Overflow
    // ============================================================

    public function clipContent(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'overflow-hidden', ['overflow' => 'hidden']);
        return $this;
    }

    public function overflow(?Pseudo $pseudo = null): static
    {
        return $this->clipContent($pseudo);
    }

    public function scrollable(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'overflow-auto', ['overflow' => 'auto']);
        return $this;
    }

    /**
     * Clip horizontal overflow without creating a scroll context. Uses
     * `overflow-x: clip` — unlike `overflow-x: hidden`, this does NOT
     * force the other axis to `auto`, so the element won't sprout an
     * internal vertical scrollbar on tall pages. Use this on the
     * outermost page container to prevent any descendant from causing
     * horizontal page scroll.
     */
    public function clipX(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'overflow-x-clip', ['overflow-x' => 'clip']);
        return $this;
    }

    public function clipY(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'overflow-y-clip', ['overflow-y' => 'clip']);
        return $this;
    }

    public function scrollableX(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'overflow-x-auto', ['overflow-x' => 'auto']);
        return $this;
    }

    public function scrollableY(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'overflow-y-auto', ['overflow-y' => 'auto']);
        return $this;
    }

    // ============================================================
    // Typography
    // ============================================================

    public function fontSize(FontSize $size, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $size->toClass(), ['font-size' => $size->getCssValue()]);
        return $this;
    }

    public function weight(FontWeight $weight, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $weight->toClass(), ['font-weight' => $weight->getCssValue()]);
        return $this;
    }

    // ============================================================
    // Cursor
    // ============================================================

    public function cursor(Cursor $cursor, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $cursor->toClass(), ['cursor' => $cursor->getCssValue()]);
        return $this;
    }

    public function clickable(?Pseudo $pseudo = null): static
    {
        return $this->cursor(Cursor::Pointer, $pseudo);
    }

    public function notAllowed(?Pseudo $pseudo = null): static
    {
        return $this->cursor(Cursor::NotAllowed, $pseudo);
    }

    // ============================================================
    // Transitions
    // ============================================================

    public function animated(int $durationMs = 200, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'transition', ['transition-property' => 'all', 'transition-timing-function' => 'cubic-bezier(0.4, 0, 0.2, 1)']);
        $this->addStyle($prefix . 'duration-' . $durationMs, ['transition-duration' => $durationMs . 'ms']);
        return $this;
    }

    // ============================================================
    // Transforms
    // ============================================================

    public function rotate(int $degrees, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'rotate-' . $degrees, ['transform' => 'rotate(' . $degrees . 'deg)']);
        return $this;
    }

    public function scale(int $percent, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'scale-' . $percent, ['transform' => 'scale(' . ($percent / 100) . ')']);
        return $this;
    }

    public function flipX(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . '-scale-x-100', ['transform' => 'scaleX(-1)']);
        return $this;
    }

    public function flipY(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . '-scale-y-100', ['transform' => 'scaleY(-1)']);
        return $this;
    }

    // ============================================================
    // Z-Index / Layering
    // ============================================================

    public function zIndex(int $index, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'z-' . $index, ['z-index' => (string)$index]);
        return $this;
    }

    // ============================================================
    // Flex item properties
    // ============================================================

    public function grow(int $factor = 1, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'grow-' . $factor, ['flex-grow' => (string)$factor]);
        return $this;
    }

    public function noShrink(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'shrink-0', ['flex-shrink' => '0']);
        return $this;
    }

    // ============================================================
    // Position
    // ============================================================

    public function relative(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'relative', ['position' => 'relative']);
        return $this;
    }

    public function absolute(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'absolute', ['position' => 'absolute']);
        return $this;
    }

    public function fixed(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'fixed', ['position' => 'fixed']);
        return $this;
    }

    public function sticky(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'sticky', ['position' => 'sticky']);
        return $this;
    }

    /**
     * CSS `inset` shorthand applied uniformly to all four sides.
     */
    public function inset(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $css = $value->getCssValue();
        $class = 'inset-[' . str_replace(' ', '_', $css) . ']';
        $this->addStyle($prefix . $class, ['inset' => $css]);
        return $this;
    }

    public function offsetTop(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('top'), ['top' => $value->getCssValue()]);
        return $this;
    }

    public function offsetLeft(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('left'), ['left' => $value->getCssValue()]);
        return $this;
    }

    public function offsetRight(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('right'), ['right' => $value->getCssValue()]);
        return $this;
    }

    public function offsetBottom(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('bottom'), ['bottom' => $value->getCssValue()]);
        return $this;
    }

    // ============================================================
    // Display
    // ============================================================

    public function block(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'block', ['display' => 'block']);
        return $this;
    }

    public function inlineBlock(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'inline-block', ['display' => 'inline-block']);
        return $this;
    }

    public function inline(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'inline', ['display' => 'inline']);
        return $this;
    }

    public function flex(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'flex', ['display' => 'flex']);
        return $this;
    }

    public function inlineFlex(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'inline-flex', ['display' => 'inline-flex']);
        return $this;
    }

    public function grid(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'grid', ['display' => 'grid']);
        return $this;
    }

    public function inlineGrid(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'inline-grid', ['display' => 'inline-grid']);
        return $this;
    }

    public function table(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'table', ['display' => 'table']);
        return $this;
    }

    public function contents(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'contents', ['display' => 'contents']);
        return $this;
    }

    // ============================================================
    // Aspect ratio
    // ============================================================

    public function aspectSquare(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'aspect-square', ['aspect-ratio' => '1 / 1']);
        return $this;
    }

    public function aspectVideo(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'aspect-video', ['aspect-ratio' => '16 / 9']);
        return $this;
    }

    public function aspectAuto(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'aspect-auto', ['aspect-ratio' => 'auto']);
        return $this;
    }

    public function aspectRatio(int $width, int $height, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . "aspect-[{$width}/{$height}]", ['aspect-ratio' => "$width / $height"]);
        return $this;
    }

    // ============================================================
    // Object fit / position
    // ============================================================

    public function objectCover(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-cover', ['object-fit' => 'cover']);
        return $this;
    }

    public function objectContain(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-contain', ['object-fit' => 'contain']);
        return $this;
    }

    public function objectFill(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-fill', ['object-fit' => 'fill']);
        return $this;
    }

    public function objectNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-none', ['object-fit' => 'none']);
        return $this;
    }

    public function objectScaleDown(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-scale-down', ['object-fit' => 'scale-down']);
        return $this;
    }

    public function objectCenter(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-center', ['object-position' => 'center']);
        return $this;
    }

    public function objectTop(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-top', ['object-position' => 'top']);
        return $this;
    }

    public function objectBottom(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-bottom', ['object-position' => 'bottom']);
        return $this;
    }

    public function objectLeft(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-left', ['object-position' => 'left']);
        return $this;
    }

    public function objectRight(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'object-right', ['object-position' => 'right']);
        return $this;
    }

    // ============================================================
    // Isolation & blending
    // ============================================================

    public function isolate(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'isolate', ['isolation' => 'isolate']);
        return $this;
    }

    public function isolationAuto(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'isolation-auto', ['isolation' => 'auto']);
        return $this;
    }

    public function mixBlend(string $mode, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'mix-blend-' . $mode, ['mix-blend-mode' => $mode]);
        return $this;
    }

    // ============================================================
    // Spacing — single-side variants
    // ============================================================

    public function paddingRight(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('pr'), ['padding-right' => $value->getCssValue()]);
        return $this;
    }

    public function paddingBottom(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('pb'), ['padding-bottom' => $value->getCssValue()]);
        return $this;
    }

    public function paddingLeft(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('pl'), ['padding-left' => $value->getCssValue()]);
        return $this;
    }

    public function marginTop(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('mt'), ['margin-top' => $value->getCssValue()]);
        return $this;
    }

    public function marginRight(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('mr'), ['margin-right' => $value->getCssValue()]);
        return $this;
    }

    public function marginBottom(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('mb'), ['margin-bottom' => $value->getCssValue()]);
        return $this;
    }

    public function marginLeft(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('ml'), ['margin-left' => $value->getCssValue()]);
        return $this;
    }

    // ============================================================
    // Borders — single-side variants
    // ============================================================

    public function borderLeft(int $width = 1, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'border-l-' . $width, ['border-left-width' => $width . 'px', 'border-left-style' => 'solid']);
        return $this;
    }

    public function borderRight(int $width = 1, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'border-r-' . $width, ['border-right-width' => $width . 'px', 'border-right-style' => 'solid']);
        return $this;
    }

    // ============================================================
    // Rounded — per-corner / per-side
    // ============================================================

    public function roundedTop(Unit|int|null $value = null, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        if ($value === null) {
            $this->addStyle($prefix . 'rounded-t', ['border-top-left-radius' => '0.25rem', 'border-top-right-radius' => '0.25rem']);
        } else {
            $css = $value->getCssValue();
            $this->addStyle($prefix . $value->withContext('rounded-t'), ['border-top-left-radius' => $css, 'border-top-right-radius' => $css]);
        }
        return $this;
    }

    public function roundedBottom(Unit|int|null $value = null, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        if ($value === null) {
            $this->addStyle($prefix . 'rounded-b', ['border-bottom-left-radius' => '0.25rem', 'border-bottom-right-radius' => '0.25rem']);
        } else {
            $css = $value->getCssValue();
            $this->addStyle($prefix . $value->withContext('rounded-b'), ['border-bottom-left-radius' => $css, 'border-bottom-right-radius' => $css]);
        }
        return $this;
    }

    public function roundedLeft(Unit|int|null $value = null, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        if ($value === null) {
            $this->addStyle($prefix . 'rounded-l', ['border-top-left-radius' => '0.25rem', 'border-bottom-left-radius' => '0.25rem']);
        } else {
            $css = $value->getCssValue();
            $this->addStyle($prefix . $value->withContext('rounded-l'), ['border-top-left-radius' => $css, 'border-bottom-left-radius' => $css]);
        }
        return $this;
    }

    public function roundedRight(Unit|int|null $value = null, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        if ($value === null) {
            $this->addStyle($prefix . 'rounded-r', ['border-top-right-radius' => '0.25rem', 'border-bottom-right-radius' => '0.25rem']);
        } else {
            $css = $value->getCssValue();
            $this->addStyle($prefix . $value->withContext('rounded-r'), ['border-top-right-radius' => $css, 'border-bottom-right-radius' => $css]);
        }
        return $this;
    }

    public function roundedTopLeft(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('rounded-tl'), ['border-top-left-radius' => $value->getCssValue()]);
        return $this;
    }

    public function roundedTopRight(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('rounded-tr'), ['border-top-right-radius' => $value->getCssValue()]);
        return $this;
    }

    public function roundedBottomLeft(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('rounded-bl'), ['border-bottom-left-radius' => $value->getCssValue()]);
        return $this;
    }

    public function roundedBottomRight(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('rounded-br'), ['border-bottom-right-radius' => $value->getCssValue()]);
        return $this;
    }

    // ============================================================
    // Outline
    // ============================================================

    public function outline(int $width = 1, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'outline-' . $width, ['outline-width' => $width . 'px', 'outline-style' => 'solid']);
        return $this;
    }

    public function outlineColor(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $color->withContext('outline'), ['outline-color' => $color->getValue()]);
        return $this;
    }

    public function outlineDashed(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'outline-dashed', ['outline-style' => 'dashed']);
        return $this;
    }

    public function outlineDotted(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'outline-dotted', ['outline-style' => 'dotted']);
        return $this;
    }

    public function outlineOffset(int $px, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'outline-offset-' . $px, ['outline-offset' => $px . 'px']);
        return $this;
    }

    // ============================================================
    // Ring (focus ring / box-shadow ring)
    // ============================================================

    public function ring(int $width = 3, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'ring-' . $width, ['box-shadow' => "0 0 0 {$width}px currentColor"]);
        return $this;
    }

    public function ringColor(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $value = $color->getValue();
        $this->addStyle($prefix . $color->withContext('ring'), ['--tw-ring-color' => $value, 'box-shadow' => "0 0 0 3px $value"]);
        return $this;
    }

    public function ringOffset(int $px, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'ring-offset-' . $px, ['--tw-ring-offset-width' => $px . 'px']);
        return $this;
    }

    // ============================================================
    // Transforms — translate, skew, origin, GPU
    // ============================================================

    public function translateX(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('translate-x'), ['transform' => 'translateX(' . $value->getCssValue() . ')']);
        return $this;
    }

    public function translateY(Unit|int $value, ?Pseudo $pseudo = null): static
    {
        $value = self::unit($value);
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $value->withContext('translate-y'), ['transform' => 'translateY(' . $value->getCssValue() . ')']);
        return $this;
    }

    public function skewX(int $degrees, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'skew-x-' . $degrees, ['transform' => 'skewX(' . $degrees . 'deg)']);
        return $this;
    }

    public function skewY(int $degrees, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'skew-y-' . $degrees, ['transform' => 'skewY(' . $degrees . 'deg)']);
        return $this;
    }

    public function transformOrigin(string $origin, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $class = 'origin-' . str_replace(' ', '-', $origin);
        $this->addStyle($prefix . $class, ['transform-origin' => $origin]);
        return $this;
    }

    public function transformGpu(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'transform-gpu', ['transform' => 'translateZ(0)']);
        return $this;
    }

    // ============================================================
    // Transitions
    // ============================================================

    public function transitionAll(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'transition-all', ['transition-property' => 'all']);
        return $this;
    }

    public function transitionColors(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'transition-colors', ['transition-property' => 'color, background-color, border-color, fill, stroke']);
        return $this;
    }

    public function transitionOpacity(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'transition-opacity', ['transition-property' => 'opacity']);
        return $this;
    }

    public function transitionShadow(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'transition-shadow', ['transition-property' => 'box-shadow']);
        return $this;
    }

    public function transitionTransform(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'transition-transform', ['transition-property' => 'transform']);
        return $this;
    }

    public function transitionNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'transition-none', ['transition-property' => 'none']);
        return $this;
    }

    public function duration(int $ms, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'duration-' . $ms, ['transition-duration' => $ms . 'ms']);
        return $this;
    }

    public function delay(int $ms, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'delay-' . $ms, ['transition-delay' => $ms . 'ms']);
        return $this;
    }

    public function easeLinear(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'ease-linear', ['transition-timing-function' => 'linear']);
        return $this;
    }

    public function easeIn(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'ease-in', ['transition-timing-function' => 'cubic-bezier(0.4, 0, 1, 1)']);
        return $this;
    }

    public function easeOut(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'ease-out', ['transition-timing-function' => 'cubic-bezier(0, 0, 0.2, 1)']);
        return $this;
    }

    public function easeInOut(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'ease-in-out', ['transition-timing-function' => 'cubic-bezier(0.4, 0, 0.2, 1)']);
        return $this;
    }

    // ============================================================
    // Filters
    // ============================================================

    public function blur(int $px, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'blur-' . $px, ['filter' => 'blur(' . $px . 'px)']);
        return $this;
    }

    public function brightness(int $percent, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'brightness-' . $percent, ['filter' => 'brightness(' . ($percent / 100) . ')']);
        return $this;
    }

    public function contrast(int $percent, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'contrast-' . $percent, ['filter' => 'contrast(' . ($percent / 100) . ')']);
        return $this;
    }

    public function grayscale(int $percent = 100, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'grayscale-' . $percent, ['filter' => 'grayscale(' . ($percent / 100) . ')']);
        return $this;
    }

    public function invert(int $percent = 100, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'invert-' . $percent, ['filter' => 'invert(' . ($percent / 100) . ')']);
        return $this;
    }

    public function saturate(int $percent, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'saturate-' . $percent, ['filter' => 'saturate(' . ($percent / 100) . ')']);
        return $this;
    }

    public function sepia(int $percent = 100, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'sepia-' . $percent, ['filter' => 'sepia(' . ($percent / 100) . ')']);
        return $this;
    }

    public function hueRotate(int $degrees, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'hue-rotate-' . $degrees, ['filter' => 'hue-rotate(' . $degrees . 'deg)']);
        return $this;
    }

    public function filterNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'filter-none', ['filter' => 'none']);
        return $this;
    }

    // ============================================================
    // Backdrop filters
    // ============================================================

    public function backdropBlur(int $px, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'backdrop-blur-' . $px, ['backdrop-filter' => 'blur(' . $px . 'px)']);
        return $this;
    }

    public function backdropBrightness(int $percent, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'backdrop-brightness-' . $percent, ['backdrop-filter' => 'brightness(' . ($percent / 100) . ')']);
        return $this;
    }

    public function backdropContrast(int $percent, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'backdrop-contrast-' . $percent, ['backdrop-filter' => 'contrast(' . ($percent / 100) . ')']);
        return $this;
    }

    public function backdropGrayscale(int $percent = 100, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'backdrop-grayscale-' . $percent, ['backdrop-filter' => 'grayscale(' . ($percent / 100) . ')']);
        return $this;
    }

    public function backdropSaturate(int $percent, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'backdrop-saturate-' . $percent, ['backdrop-filter' => 'saturate(' . ($percent / 100) . ')']);
        return $this;
    }

    // ============================================================
    // SVG fill & stroke
    // ============================================================

    public function fill(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $color->withContext('fill'), ['fill' => $color->getValue()]);
        return $this;
    }

    public function stroke(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $color->withContext('stroke'), ['stroke' => $color->getValue()]);
        return $this;
    }

    public function strokeWidth(int $width, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'stroke-' . $width, ['stroke-width' => (string)$width]);
        return $this;
    }

    // ============================================================
    // User select
    // ============================================================

    public function selectNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'select-none', ['user-select' => 'none']);
        return $this;
    }

    public function selectText(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'select-text', ['user-select' => 'text']);
        return $this;
    }

    public function selectAll(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'select-all', ['user-select' => 'all']);
        return $this;
    }

    public function selectAuto(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'select-auto', ['user-select' => 'auto']);
        return $this;
    }

    // ============================================================
    // Pointer events
    // ============================================================

    public function pointerEventsNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'pointer-events-none', ['pointer-events' => 'none']);
        return $this;
    }

    public function pointerEventsAuto(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'pointer-events-auto', ['pointer-events' => 'auto']);
        return $this;
    }

    // ============================================================
    // Touch action
    // ============================================================

    public function touchAuto(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'touch-auto', ['touch-action' => 'auto']);
        return $this;
    }

    public function touchNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'touch-none', ['touch-action' => 'none']);
        return $this;
    }

    public function touchPanX(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'touch-pan-x', ['touch-action' => 'pan-x']);
        return $this;
    }

    public function touchPanY(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'touch-pan-y', ['touch-action' => 'pan-y']);
        return $this;
    }

    public function touchManipulation(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'touch-manipulation', ['touch-action' => 'manipulation']);
        return $this;
    }

    // ============================================================
    // Resize
    // ============================================================

    public function resize(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'resize', ['resize' => 'both']);
        return $this;
    }

    public function resizeNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'resize-none', ['resize' => 'none']);
        return $this;
    }

    public function resizeX(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'resize-x', ['resize' => 'horizontal']);
        return $this;
    }

    public function resizeY(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'resize-y', ['resize' => 'vertical']);
        return $this;
    }

    // ============================================================
    // Will-change
    // ============================================================

    public function willChangeAuto(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'will-change-auto', ['will-change' => 'auto']);
        return $this;
    }

    public function willChangeScroll(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'will-change-scroll', ['will-change' => 'scroll-position']);
        return $this;
    }

    public function willChangeContents(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'will-change-contents', ['will-change' => 'contents']);
        return $this;
    }

    public function willChangeTransform(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'will-change-transform', ['will-change' => 'transform']);
        return $this;
    }

    // ============================================================
    // Appearance, caret, accent
    // ============================================================

    public function appearanceNone(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'appearance-none', ['appearance' => 'none', '-webkit-appearance' => 'none']);
        return $this;
    }

    public function appearanceAuto(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'appearance-auto', ['appearance' => 'auto']);
        return $this;
    }

    public function caretColor(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $color->withContext('caret'), ['caret-color' => $color->getValue()]);
        return $this;
    }

    public function accentColor(Color $color, ?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . $color->withContext('accent'), ['accent-color' => $color->getValue()]);
        return $this;
    }

    // ============================================================
    // Scroll behavior
    // ============================================================

    public function scrollSmooth(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'scroll-smooth', ['scroll-behavior' => 'smooth']);
        return $this;
    }

    public function scrollAuto(?Pseudo $pseudo = null): static
    {
        $prefix = $pseudo?->prefix() ?? '';
        $this->addStyle($prefix . 'scroll-auto', ['scroll-behavior' => 'auto']);
        return $this;
    }
}
