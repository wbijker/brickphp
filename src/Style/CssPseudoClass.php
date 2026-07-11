<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * CSS pseudo-class, strongly typed. This type owns its own static lookup: {@see ALL}
 * is the source of the selector strings (with the leading ':'), and each case is
 * backed by the NUMERIC INDEX into it — the wire id for the codec's `110x xxxx`
 * pseudo-class byte (5-bit payload). Resolve the selector with {@see css()}.
 *
 * APPEND-ONLY — the index is the wire id, so never renumber cases or reorder ALL.
 *
 * Usage:
 *   CssPseudoClass::hover                // ->value === 0 (wire id)
 *   CssPseudoClass::firstChild->css()   // ':first-child'
 */
enum CssPseudoClass: int
{
    case hover = 0;  // :hover
    case focus = 1;  // :focus
    case active = 2;  // :active
    case visited = 3;  // :visited
    case link = 4;  // :link
    case firstChild = 5;  // :first-child
    case lastChild = 6;  // :last-child
    case nthChild = 7;  // :nth-child
    case nthOfType = 8;  // :nth-of-type
    case firstOfType = 9;  // :first-of-type
    case lastOfType = 10;  // :last-of-type
    case onlyChild = 11;  // :only-child
    case onlyOfType = 12;  // :only-of-type
    case not = 13;  // :not
    case is = 14;  // :is
    case where = 15;  // :where
    case has = 16;  // :has
    case checked = 17;  // :checked
    case disabled = 18;  // :disabled
    case enabled = 19;  // :enabled
    case required = 20;  // :required
    case optional = 21;  // :optional
    case valid = 22;  // :valid
    case invalid = 23;  // :invalid
    case focusWithin = 24;  // :focus-within
    case focusVisible = 25;  // :focus-visible
    case root = 26;  // :root
    case empty = 27;  // :empty
    case target = 28;  // :target
    case default = 29;  // :default
    case placeholderShown = 30;  // :placeholder-shown
    case readOnly = 31;  // :read-only

    /** All pseudo-class selectors, indexed by wire id. Source of truth for this type. */
    public const ALL = [
        ':hover', ':focus', ':active', ':visited', ':link', ':first-child', ':last-child',
        ':nth-child', ':nth-of-type', ':first-of-type', ':last-of-type', ':only-child',
        ':only-of-type', ':not', ':is', ':where', ':has', ':checked', ':disabled',
        ':enabled', ':required', ':optional', ':valid', ':invalid', ':focus-within',
        ':focus-visible', ':root', ':empty', ':target', ':default', ':placeholder-shown',
        ':read-only',
    ];

    /** The pseudo-class selector for this case (with leading ':'). */
    public function css(): string
    {
        return self::ALL[$this->value];
    }
}
