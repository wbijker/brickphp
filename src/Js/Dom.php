<?php

namespace BrickPHP\Js;

/**
 * Document-level commands a server-side handler can issue about a specific
 * element, in the same family as {@see Document}, {@see Window} and
 * {@see App}: the caller writes PHP, the JS is built here from the typed
 * expression builders.
 *
 * These are commands, not state — "focus this now", not "this should be
 * focused". Declarative focus at first render is the input's own
 * `autofocus()`; that fires when the browser inserts the element and never
 * again, so a handler that wants focus back on a node the patch left in
 * place has to say so.
 */
class Dom
{
    /** Move focus to the element with this id, if it is on the page. */
    static function focus(string $id): void
    {
        Js::run(Js::invoke(Js::obj('Brick', 'focusElement'), Js::str($id)));
    }

    /** Take focus off the element with this id, if it currently holds it. */
    static function blur(string $id): void
    {
        Js::run(Js::invoke(Js::obj('Brick', 'blurElement'), Js::str($id)));
    }
}
