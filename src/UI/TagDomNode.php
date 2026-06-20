<?php

namespace BrickPHP\UI;

use BrickPHP\Events\EventData;
use BrickPHP\Events\EventPhase;
use BrickPHP\Events\EventRegistration;
use BrickPHP\Events\NativeEventRegistration;
use BrickPHP\State\StateManager;
use BrickPHP\UI\Css\CssStyle;
use BrickPHP\VNode\Component;
use BrickPHP\VNode\Patcher;

/**
 * Represents an element node in the DOM.
 * Base class for all UI elements.
 */
class TagDomNode extends DomNode
{
    /** Maps custom event names to their DOM event attribute equivalents */
    private const DOM_EVENT_MAP = [
        'upload' => 'change',
    ];

    /** Void (self-closing) tags — keyed for O(1) lookup in toHtml(). */
    private const SELF_CLOSING = [
        'img' => true, 'br' => true, 'hr' => true, 'input' => true, 'meta' => true,
        'link' => true, 'area' => true, 'base' => true, 'col' => true, 'embed' => true,
        'source' => true, 'track' => true, 'wbr' => true,
    ];

    /** @var (DomNode|string)[] */
    protected array $children = [];

    /** @var array<string, string> */
    protected array $attributes = [];

    /** @var array<string, array<string, string>> */
    protected array $styles = [];

    /** @var CssStyle[] */
    protected array $cssStyles = [];

    /** @var array<string, array{callback: callable, owner: ?Component, registration: ?EventRegistration}> */
    protected array $events = [];

    /** @var string[] Collected class names */
    protected array $classes = [];

    /** @var array<string, true> Attribute names that should be force-patched on every diff */
    protected array $invalidatedAttrs = [];

    public function markInvalidatedAttr(string $name): static
    {
        $this->invalidatedAttrs[$name] = true;
        return $this;
    }

    /**
     * Label shown in the top-left corner of this node when the page is
     * rendered in `?wireframe=true` mode. Stamped by UIElement::__construct
     * with the element class short-name (lowercase), then overwritten by
     * Component::render with the component class short-name (capitalised)
     * at component boundaries.
     */
    public ?string $wireframeLabel = null;

    /**
     * Source location captured in wireframe mode — the file:line where this
     * node was instantiated in user code (for UI elements) or the file where
     * the component class is declared (for components). Surfaced by the
     * frontend on ctrl+click so the dev can jump straight to the source.
     */
    public ?string $wireframeFile = null;
    public ?int $wireframeLine = null;

    /** @var bool Whether this node has a bound value ref */
    private bool $hasBoundRef = false;

    /** @var mixed Reference to the bound component property */
    private mixed $boundRef = null;

    public function __construct(
        protected string $tag
    ) {
    }

    /**
     * Get the tag name.
     */
    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * Set the tag name.
     */
    public function setTag(string $tag): static
    {
        $this->tag = $tag;
        return $this;
    }

    /**
     * Set a key for efficient list diffing.
     */
    public function key(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    /**
     * Set an attribute.
     */
    public function attr(string $name, string $value): static
    {
        $this->attributes[$name] = $value;
        return $this;
    }

    /**
     * Set multiple attributes.
     * @param array<string, string> $attributes
     */
    public function attrs(array $attributes): static
    {
        $this->attributes = array_merge($this->attributes, $attributes);
        return $this;
    }

    /**
     * Get all attributes.
     * @return array<string, string>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Add CSS classes.
     */
    public function class(string ...$classes): static
    {
        $this->classes = array_merge($this->classes, $classes);
        return $this;
    }

    /**
     * Add a class with its CSS properties (legacy format).
     */
    protected function addStyle(string $class, array $css): void
    {
        $this->classes[] = $class;
        if (!empty($css)) {
            $this->styles[$class] = $css;
        }
    }

    /**
     * Add a CssStyle object.
     */
    protected function addCssStyle(CssStyle $style): void
    {
        $this->classes[] = $style->getClassName();
        $this->cssStyles[] = $style;
    }

    /**
     * Add a style rule for CSS generation.
     * @param array<string, string> $css
     */
    public function style(string $className, array $css): static
    {
        $this->addStyle($className, $css);
        return $this;
    }

    /**
     * Add a CssStyle rule.
     */
    public function css(CssStyle $style): static
    {
        $this->addCssStyle($style);
        return $this;
    }

    /**
     * Add an event listener. Every event carries an EventRegistration that
     * the diff drives (add/remove/delete): native DOM events default to a
     * NativeEventRegistration (addEventListener, with $phase choosing the
     * bubble/capture phase), custom events (e.g. Leaflet's map click) pass
     * their own. There are no inline on<event> attributes — all wiring is
     * imperative JS keyed on the node's path.
     */
    /**
     * @param string[] $keys For keyboard events, the `KeyboardEvent.key` values
     *   the handler is restricted to. Empty = every key. The filtering happens
     *   client-side (see NativeEventRegistration), so non-matching keys never
     *   reach the server. Ignored when an explicit $registration is supplied.
     */
    public function on(string $event, callable $callback, ?Component $owner = null, ?EventRegistration $registration = null, EventPhase $phase = EventPhase::Bubble, bool $client = true, array $keys = []): static
    {
        // Normalize any callable shape (string, [obj,'method'], invokable
        // object, first-class callable) into a Closure at the DOM-node
        // boundary so every downstream consumer can rely on a uniform
        // type when storing and dispatching the handler.
        $closure = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
        $registration ??= new NativeEventRegistration($event, self::DOM_EVENT_MAP[$event] ?? $event, $phase, $client, $keys);
        $this->events[$event] = ['callback' => $closure, 'owner' => $owner, 'registration' => $registration];
        return $this;
    }

    /**
     * Get all event listeners.
     * @return array<string, array{callback: callable, owner: ?Component}>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Visit every event registration in this subtree, calling
     * $visit($registration, int[] $path). The basis for bindEvents()/
     * unbindEvents() (see DomNode). Walks plain children; ListDomNode
     * overrides to walk its keyed children.
     *
     * @param callable(EventRegistration, int[]): void $visit
     */
    public function walkRegistrations(callable $visit): void
    {
        $this->walkOwnRegistrations($visit);

        foreach ($this->children as $child) {
            if ($child instanceof DomNode) {
                $child->walkRegistrations($visit);
            }
        }
    }

    /**
     * Visit this node's own event registrations (no recursion). Shared by
     * TagDomNode and ListDomNode, which differ only in child storage.
     *
     * @param callable(EventRegistration, int[]): void $visit
     */
    protected function walkOwnRegistrations(callable $visit): void
    {
        foreach ($this->events as $data) {
            $visit($data['registration'], $this->path);
        }
    }

    /**
     * Diff this (NEW) node's own listeners against the OLD node at the same
     * position: bind listeners that appeared, unbind ones that vanished.
     * Listeners present in both are left untouched (no update) — the binding
     * from the previous render persists on the surviving element.
     */
    public function diffEvents(TagDomNode $old): void
    {
        foreach ($this->events as $event => $data) {
            if (!isset($old->events[$event])) {
                $data['registration']->add($this->path);
            }
        }
        foreach ($old->events as $event => $data) {
            if (!isset($this->events[$event])) {
                $data['registration']->remove($this->path);
            }
        }
    }

    /**
     * Drop every registered listener — the node renders without any on*
     * attributes after this. Used by the wireframe renderer so an accidental
     * click during inspection doesn't kick off the real app handler.
     */
    public function clearEvents(): static
    {
        $this->events = [];
        return $this;
    }

    /**
     * Set the owner component for all events.
     */
    public function setEventOwner(Component $owner): static
    {
        foreach ($this->events as $event => $data) {
            $this->events[$event]['owner'] = $owner;
        }
        return $this;
    }

    public function bindRef(mixed &$ref): void
    {
        $this->boundRef = &$ref;
        $this->hasBoundRef = true;
    }

    public function isBound(): bool
    {
        return $this->hasBoundRef;
    }

    public function hydrateBinding(string $value): void
    {
        if (!$this->hasBoundRef) {
            return;
        }
        $this->boundRef = $value;

        // Sync the rendered value so the OLD tree matches what was on-screen.
        // Without this, the diff against the NEW tree won't detect backend-driven
        // resets (e.g. clearing an input after submit) and no patch is emitted.
        if ($this->tag === 'textarea') {
            $this->children = [new TextDomNode($value)];
        } else {
            $this->attributes['value'] = $value;
        }
    }

    /**
     * Find all bound nodes in the tree and hydrate them from the bindings map.
     * @param array<string, string> $bindings Path string => value
     */
    public function hydrateBindings(array $bindings): void
    {
        $pathStr = implode(',', $this->path);
        if ($this->hasBoundRef && isset($bindings[$pathStr])) {
            $this->hydrateBinding($bindings[$pathStr]);
        }

        foreach ($this->children as $child) {
            if ($child instanceof TagDomNode) {
                $child->hydrateBindings($bindings);
            }
        }
    }

    /**
     * Add child nodes or text.
     */
    public function content(DomNode|string|null ...$children): static
    {
        foreach ($children as $child) {
            if ($child === null) {
                $this->children[] = new CommentDomNode();
            } elseif ($child instanceof DomNode) {
                $this->children[] = $child;
            } else {
                $this->children[] = new TextDomNode($child);
            }
        }
        return $this;
    }

    /**
     * Alias for content().
     */
    public function children(DomNode|string|null ...$children): static
    {
        return $this->content(...$children);
    }

    /**
     * Remove all children. Used when an element builds its children
     * derivatively (e.g. an SVG node from a list of SvgElements) and must
     * stay idempotent across re-renders.
     */
    public function clearChildren(): static
    {
        $this->children = [];
        return $this;
    }

    /**
     * Add raw HTML content without wrapping in TextDomNode.
     */
    public function rawContent(string $content): static
    {
        $this->children[] = new RawContent($content);
        return $this;
    }

    /**
     * Get all children.
     * @return DomNode[]
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    /**
     * Assign paths to child nodes.
     */
    protected function assignChildPaths(): void
    {
        foreach ($this->children as $index => $child) {
            $child->assignPaths([...$this->path, $index]);
        }
    }

    /**
     * Collect all styles from this node and descendants.
     * @return array<string, array<string, string>>
     */
    public function collectStyles(): array
    {
        $allStyles = $this->styles;

        // Include CssStyle objects in legacy format
        foreach ($this->cssStyles as $style) {
            $legacy = $style->toLegacy();
            $allStyles = array_merge($allStyles, $legacy);
        }

        foreach ($this->children as $child) {
            if ($child instanceof DomNode) {
                $allStyles = array_merge($allStyles, $child->collectStyles());
            }
        }

        return $allStyles;
    }

    /**
     * Render to HTML string.
     */
    public function toHtml(): string
    {
        // Build class attribute
        $allClasses = $this->classes;
        if (isset($this->attributes['class'])) {
            $allClasses = array_merge(explode(' ', $this->attributes['class']), $allClasses);
        }

        // Build attributes
        $attrHtml = '';

        if ($this->managed && $this->hasBoundRef) {
            $attrHtml .= ' data-bind';
        }

        if (!empty($allClasses)) {
            $attrHtml .= ' class="' . htmlspecialchars(implode(' ', array_unique($allClasses))) . '"';
        }
        foreach ($this->attributes as $name => $value) {
            if ($name === 'class') continue;
            $attrHtml .= ' ' . $name . '="' . htmlspecialchars($value) . '"';
        }

        // Events carry no inline on<event> attributes — every listener is
        // wired imperatively via its EventRegistration (Brick.bindEvent),
        // driven by the diff. See on() / NativeEventRegistration.

        // Auto-emit wireframe/inspect data attrs whenever the fields are set
        // (UIElement + Component stamp them only when UIElement::$captureSource
        // is on, which Brick flips in dev mode). Kept out of $attributes so the
        // diff path never sees them — they're presentation-only metadata.
        if ($this->wireframeLabel !== null) {
            $attrHtml .= ' data-wf-label="' . htmlspecialchars($this->wireframeLabel) . '"';
        }
        if ($this->wireframeFile !== null) {
            $attrHtml .= ' data-wf-file="' . htmlspecialchars($this->wireframeFile) . '"';
        }
        if ($this->wireframeLine !== null) {
            $attrHtml .= ' data-wf-line="' . $this->wireframeLine . '"';
        }

        // Self-closing (void) tags
        if (isset(self::SELF_CLOSING[$this->tag]) && empty($this->children)) {
            return "<{$this->tag}{$attrHtml}>";
        }

        // Build children
        $childrenHtml = '';
        foreach ($this->children as $child) {
            $childrenHtml .= $child->toHtml();
        }

        return "<{$this->tag}{$attrHtml}>{$childrenHtml}</{$this->tag}>";
    }

    /**
     * Find a node by its path.
     * @param int[] $targetPath
     * @return DomNode|null
     */
    public function findByPath(array $targetPath): ?DomNode
    {
        if ($this->path === $targetPath) {
            return $this;
        }

        foreach ($this->children as $child) {
            if ($child instanceof DomNode) {
                $found = $child->findByPath($targetPath);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    public function countNodes(): int
    {
        $count = 1;
        foreach ($this->children as $child) {
            $count += $child->countNodes();
        }
        return $count;
    }

    /**
     * Execute an event handler if it exists.
     * @param string $event
     * @param StateManager|null $state The state manager for finalizing the owner component
     * @return bool Whether the event was handled
     */
    public function executeEvent(string $event, mixed $state = null, mixed $value = null): bool
    {
        if (isset($this->events[$event])) {
            $eventData = $this->events[$event];
            $typed = EventData::hydrate($event, $value);
            ($eventData['callback'])($typed);

            // Finalize the owner component if available
            if ($state !== null && $eventData['owner'] !== null) {
                $eventData['owner']->finalize($state);
            }

            return true;
        }
        return false;
    }

    /**
     * Compare this node with another and generate patches.
     */
    public function compare(DomNode $other, Patcher $patcher): void
    {
        // Element opted out of diffing — its DOM is left exactly as it was
        // on the frontend. We don't even walk into the subtree.
        if ($this->frozen) {
            return;
        }

        // If other is not a TagDomNode or tag differs, replace entirely.
        // The old element (and its listeners) is discarded; the new subtree
        // is freshly materialised, so unbind the old and bind the new.
        if (!$other instanceof TagDomNode || $this->tag !== $other->tag) {
            $other->unbindEvents();
            $patcher->replaceNode($this->path, $this);
            $this->bindEvents();
            return;
        }

        // A whole-node invalidation propagates to every descendant so each
        // attribute, class, and text below us is force-emitted too.
        if ($this->invalidated) {
            foreach ($this->children as $child) {
                $child->setInvalidated(true);
            }
        }

        // Compare attributes
        $thisAttrs = $this->attributes;
        $otherAttrs = $other->attributes;

        foreach ($thisAttrs as $name => $value) {
            $forced = $this->invalidated || isset($this->invalidatedAttrs[$name]);
            if ($forced || !isset($otherAttrs[$name]) || $otherAttrs[$name] !== $value) {
                $patcher->setAttribute($this->path, $name, $value);
            }
        }

        foreach ($otherAttrs as $name => $value) {
            if (!isset($thisAttrs[$name])) {
                $patcher->removeAttribute($this->path, $name);
            }
        }

        // Compare classes
        $thisClasses = $this->classes;
        $otherClasses = $other->classes;
        $classForced = $this->invalidated || isset($this->invalidatedAttrs['class']);
        if ($classForced || $thisClasses !== $otherClasses) {
            $patcher->setAttribute($this->path, 'class', implode(' ', array_unique($thisClasses)));
        }

        // This element survives — diff its own listeners (bind appeared,
        // unbind vanished; unchanged ones keep their existing binding).
        $this->diffEvents($other);

        // Compare children
        $this->compareChildren($other, $patcher);
    }

    /**
     * Check if children are keyed (all children have a non-null key).
     */
    private function childrenAreKeyed(): bool
    {
        if (empty($this->children)) {
            return false;
        }
        foreach ($this->children as $child) {
            if ($child->getKey() === null) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compare children using positional or keyed strategy.
     */
    private function compareChildren(TagDomNode $other, Patcher $patcher): void
    {
        $newKeyed = $this->childrenAreKeyed();
        $oldKeyed = $other->childrenAreKeyed();

        if ($newKeyed && $oldKeyed) {
            $this->compareChildrenKeyed($other, $patcher);
        } else {
            $this->compareChildrenPositional($other, $patcher);
        }
    }

    /**
     * Positional child comparison (no keys).
     */
    private function compareChildrenPositional(TagDomNode $other, Patcher $patcher): void
    {
        $thisCount = count($this->children);
        $otherCount = count($other->children);
        $commonCount = min($thisCount, $otherCount);

        // Delete removed children in reverse order
        for ($i = $otherCount - 1; $i >= $thisCount; $i--) {
            $other->children[$i]->unbindEvents();
            $patcher->deleteNode([...$this->path, $i]);
        }

        // Compare common children
        for ($i = 0; $i < $commonCount; $i++) {
            $this->children[$i]->compare($other->children[$i], $patcher);
        }

        // Insert new children
        for ($i = $otherCount; $i < $thisCount; $i++) {
            $patcher->insertNode([...$this->path, $i], $this->children[$i]);
            $this->children[$i]->bindEvents();
        }
    }

    /**
     * Keyed child comparison.
     *
     * Each key maps to a FIFO queue of old indices. For each new child, accept
     * the next old index only if it is strictly greater than the most recently
     * matched old index; otherwise discard the candidate (it ends up deleted)
     * and try the next. A new child that finds no usable candidate becomes a
     * fresh insertion at its target position.
     *
     * The strictly-increasing rule lets the diff produce correct DOM order
     * without emitting move operations: anything that would have to move
     * backwards is instead delete+insert. State preservation suffers in those
     * cases — that's the price of not implementing moves.
     */
    private function compareChildrenKeyed(TagDomNode $other, Patcher $patcher): void
    {
        $oldQueues = [];
        foreach ($other->children as $i => $child) {
            $oldQueues[$child->getKey()][] = $i;
        }

        $matched = [];
        $usedOld = [];
        $lastMatched = -1;
        foreach ($this->children as $newIdx => $newChild) {
            $key = $newChild->getKey();
            while (!empty($oldQueues[$key]) && $oldQueues[$key][0] <= $lastMatched) {
                array_shift($oldQueues[$key]);
            }
            if (empty($oldQueues[$key])) {
                $matched[$newIdx] = null;
                continue;
            }
            $oldIdx = array_shift($oldQueues[$key]);
            $matched[$newIdx] = $oldIdx;
            $usedOld[$oldIdx] = true;
            $lastMatched = $oldIdx;
        }

        for ($i = count($other->children) - 1; $i >= 0; $i--) {
            if (!isset($usedOld[$i])) {
                $other->children[$i]->unbindEvents();
                $patcher->removeAt($this->path, $i);
            }
        }

        foreach ($this->children as $newIdx => $newChild) {
            if ($matched[$newIdx] === null) {
                $patcher->insertAt($this->path, $newIdx, $newChild);
                $newChild->bindEvents();
                continue;
            }
            $oldChild = $other->children[$matched[$newIdx]];
            $newChild->assignPaths([...$this->path, $newIdx]);
            $oldChild->assignPaths([...$this->path, $newIdx]);
            $newChild->compare($oldChild, $patcher);
        }
    }
}
