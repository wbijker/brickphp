<?php

namespace BrickPHP\Events;

use BrickPHP\Js\Js;

/**
 * Wires a listener on `document` that answers for an element deeper in the
 * tree — the shortcut a screen wants to hear wherever focus happens to be.
 * Without it a key handler only fires while its own element (or a descendant)
 * holds focus, so "Escape goes back" would mean asking the user to click the
 * right box first.
 *
 * Identical to {@see NativeEventRegistration} in every other respect: same
 * add/remove lifecycle driven by the diff, same client-side key filter, and
 * the dispatch reports the owning element's path, so the server resolves the
 * handler exactly as it would for a listener bound to that node directly.
 * The element is looked up when the event fires rather than when it is bound,
 * so a patch may replace it in between.
 *
 * `$preventDefault` stops the browser's own reaction before dispatching. It is
 * off by default — claiming a key is a real cost (Tab stops moving focus,
 * Escape stops closing whatever is underneath), so it has to be asked for.
 */
final class GlobalEventRegistration implements EventRegistration
{
    /** @param string[] $keys */
    public function __construct(
        private string     $event,
        private string     $domType,
        private EventPhase $phase,
        private bool       $client = true,
        private array      $keys = [],
        private bool       $preventDefault = false,
    ) {
    }

    public function eventName(): string
    {
        return $this->event;
    }

    public function add(array $path): void
    {
        Js::run(Js::invoke(
            Js::obj('Brick', 'bindGlobalEvent'),
            $path,
            Js::str($this->domType),
            Js::str($this->event),
            $this->phase->useCapture(),
            $this->client,
            $this->keys,
            $this->preventDefault,
        ));
    }

    public function remove(array $path): void
    {
        Js::run(Js::invoke(
            Js::obj('Brick', 'unbindGlobalEvent'),
            $path,
            Js::str($this->domType),
            $this->phase->useCapture(),
        ));
    }
}
