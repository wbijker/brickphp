<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * A combinator variant, strongly typed. The enum's STRING value is the token
 * used verbatim in a class name (`child`, `group-hover`); it transforms the
 * element's selector via {@see wrap()}. Two kinds:
 *
 *   structural — append a combinator + universal selector to THIS element,
 *                so the declaration targets its children/siblings
 *                (`child` → `.n > *`, `next` → `.n + *`).
 *   relational — prepend an ancestor/sibling marker so the declaration applies
 *                to THIS element when a `.group` / `.peer` is in some state
 *                (`group-hover` → `.group:hover .n`, `peer-checked` →
 *                `.peer:checked ~ .n`).
 *
 * Source of truth for the combinator token↔selector mapping the JS decoder
 * mirrors. APPEND-ONLY — a token is part of the class-name contract.
 */
enum CssCombinator: string
{
    // Structural — retarget onto descendants / siblings of this element.
    case child = 'child';    // & > *
    case next = 'next';      // & + *
    case sibling = 'sib';    // & ~ *
    case descendant = 'desc'; // & *

    // Relational — apply to this element based on a .group / .peer ancestor/sibling.
    case groupHover = 'group-hover';     // .group:hover  &
    case groupFocus = 'group-focus';     // .group:focus  &
    case groupActive = 'group-active';   // .group:active &
    case peerHover = 'peer-hover';       // .peer:hover  ~ &
    case peerFocus = 'peer-focus';       // .peer:focus  ~ &
    case peerChecked = 'peer-checked';   // .peer:checked ~ &
    case peerDisabled = 'peer-disabled'; // .peer:disabled ~ &

    /** Rewrite the element selector `$sel` (e.g. `.n`) for this combinator. */
    public function wrap(string $sel): string
    {
        return match ($this) {
            self::child      => "$sel > *",
            self::next       => "$sel + *",
            self::sibling    => "$sel ~ *",
            self::descendant => "$sel *",
            self::groupHover  => ".group:hover $sel",
            self::groupFocus  => ".group:focus $sel",
            self::groupActive => ".group:active $sel",
            self::peerHover    => ".peer:hover ~ $sel",
            self::peerFocus    => ".peer:focus ~ $sel",
            self::peerChecked  => ".peer:checked ~ $sel",
            self::peerDisabled => ".peer:disabled ~ $sel",
        };
    }
}
