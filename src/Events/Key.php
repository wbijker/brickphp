<?php

namespace BrickPHP\Events;

/**
 * Known keyboard keys, used to filter keyboard handlers down to specific keys
 * (see {@see \BrickPHP\UI\UIElement::onKeyDown()} / onKeyUp()). Each case is
 * backed by the exact value the browser reports in `KeyboardEvent.key`, so the
 * filter can compare against it verbatim on the client.
 *
 * Passing one or more of these to a keyboard handler makes the handler react —
 * and only then round-trip to the server — when a matching key is pressed; all
 * other keys are ignored client-side.
 */
enum Key: string
{
    case Enter = 'Enter';
    case Escape = 'Escape';
    case Tab = 'Tab';
    case Space = ' ';
    case Backspace = 'Backspace';
    case Delete = 'Delete';
    case ArrowUp = 'ArrowUp';
    case ArrowDown = 'ArrowDown';
    case ArrowLeft = 'ArrowLeft';
    case ArrowRight = 'ArrowRight';
    case Home = 'Home';
    case End = 'End';
    case PageUp = 'PageUp';
    case PageDown = 'PageDown';
}
