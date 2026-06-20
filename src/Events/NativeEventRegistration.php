<?php

namespace BrickPHP\Events;

use BrickPHP\Js\Js;

/**
 * Wires a native DOM listener through the diff via addEventListener, so
 * native events flow through the same add/remove lifecycle as custom ones —
 * no inline `on<event>` attributes.
 *
 * add() binds `Brick.bindEvent(path, domType, event, capture, client, keys)`;
 * remove() unbinds it. When remove() fires because the handler was dropped from a
 * surviving element it does the real detach; when it fires because the node
 * itself left the tree the element is already gone, so the emitted unbind is
 * a harmless no-op (the browser drops the listeners with the node and the
 * client's patch handler scrubs them as it removes the element).
 *
 * `$event` is the logical event name (the dispatched server event); `$domType`
 * is the real DOM event name passed to addEventListener (e.g. 'change' for
 * the logical 'upload').
 *
 * `$client` controls how a dispatch reaches the server: true (default) keeps
 * the current behaviour — an AJAX POST that diff/patches the current page;
 * false makes the listener submit a real form POST, a full-page navigation
 * the server answers with a freshly rendered HTML document.
 *
 * `$keys` is an optional client-side key filter for keyboard events: the
 * listener only dispatches when `KeyboardEvent.key` is one of these values, so
 * unwanted keys never round-trip. Empty means "every key" (the default).
 */
final class NativeEventRegistration implements EventRegistration
{
    /** @param string[] $keys */
    public function __construct(
        private string     $event,
        private string     $domType,
        private EventPhase $phase,
        private bool       $client = true,
        private array      $keys = [],
    ) {
    }

    public function eventName(): string
    {
        return $this->event;
    }

    public function add(array $path): void
    {
        Js::run(Js::invoke(
            Js::obj('Brick', 'bindEvent'),
            $path,
            Js::str($this->domType),
            Js::str($this->event),
            $this->phase->useCapture(),
            $this->client,
            $this->keys,
        ));
    }

    public function remove(array $path): void
    {
        Js::run(Js::invoke(
            Js::obj('Brick', 'unbindEvent'),
            $path,
            Js::str($this->domType),
            $this->phase->useCapture(),
        ));
    }
}
