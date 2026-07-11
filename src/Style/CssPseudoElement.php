<?php

declare(strict_types=1);

namespace BrickPHP\Style;

/**
 * CSS pseudo-element, strongly typed. This type owns its own static lookup:
 * {@see ALL} is the source of the selector strings (with the leading '::'), and
 * each case is backed by the NUMERIC INDEX into it — the wire id (one byte) for
 * the codec's extended pseudo-element wrapper. Resolve the selector with {@see css()}.
 *
 * APPEND-ONLY — the index is the wire id, so never renumber cases or reorder ALL.
 *
 * Usage:
 *   CssPseudoElement::before            // ->value === 0 (wire id)
 *   CssPseudoElement::firstLine->css()  // '::first-line'
 */
enum CssPseudoElement: int
{
    case before = 0;  // ::before
    case after = 1;  // ::after
    case placeholder = 2;  // ::placeholder
    case marker = 3;  // ::marker
    case selection = 4;  // ::selection
    case firstLine = 5;  // ::first-line
    case firstLetter = 6;  // ::first-letter
    case backdrop = 7;  // ::backdrop
    case fileSelectorButton = 8;  // ::file-selector-button
    case cue = 9;  // ::cue
    case detailsContent = 10;  // ::details-content
    case spellingError = 11;  // ::spelling-error
    case grammarError = 12;  // ::grammar-error
    case targetText = 13;  // ::target-text
    case highlight = 14;  // ::highlight
    case part = 15;  // ::part

    /** All pseudo-element selectors, indexed by wire id. Source of truth for this type. */
    public const ALL = [
        '::before', '::after', '::placeholder', '::marker', '::selection', '::first-line',
        '::first-letter', '::backdrop', '::file-selector-button', '::cue',
        '::details-content', '::spelling-error', '::grammar-error', '::target-text',
        '::highlight', '::part',
    ];

    /** The pseudo-element selector for this case (with leading '::'). */
    public function css(): string
    {
        return self::ALL[$this->value];
    }
}
