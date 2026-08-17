<?php

namespace BrickPHP\UI;

use BrickPHP\Dom\Levenshtein;
use BrickPHP\VNode\Patcher;

/**
 * A DOM node optimized for lists with keyed children.
 * Uses Levenshtein algorithm for efficient diffing.
 */
class ListDomNode extends TagDomNode
{
    /** @var KeyedDomNode[] */
    protected array $keyedChildren = [];

    /**
     * Add keyed child nodes.
     */
    public function items(KeyedDomNode ...$children): static
    {
        $this->keyedChildren = array_merge($this->keyedChildren, $children);
        return $this;
    }

    /**
     * Get keyed children.
     * @return KeyedDomNode[]
     */
    public function getKeyedChildren(): array
    {
        return $this->keyedChildren;
    }

    /**
     * Assign paths to child nodes.
     */
    protected function assignChildPaths(): void
    {
        $index = 0;
        foreach ($this->keyedChildren as $keyed) {
            $keyed->node->assignPaths([...$this->path, $index]);
            $index++;
        }
    }

    /**
     * The self-describing utility class names used by this node and its keyed
     * children (a set, name => true).
     *
     * @return array<string, true>
     */
    public function collectStyles(): array
    {
        $all = $this->utilityClasses;
        foreach ($this->keyedChildren as $keyed) {
            $all += $keyed->node->collectStyles();
        }
        return $all;
    }

    public function walkRegistrations(callable $visit): void
    {
        $this->walkOwnRegistrations($visit);

        foreach ($this->keyedChildren as $keyed) {
            $keyed->node->walkRegistrations($visit);
        }
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

        if (!empty($allClasses)) {
            $attrHtml .= ' class="' . htmlspecialchars(implode(' ', array_unique($allClasses))) . '"';
        }
        foreach ($this->attributes as $name => $value) {
            if ($name === 'class') continue;
            $attrHtml .= ' ' . $name . '="' . htmlspecialchars($value) . '"';
        }

        // Build children
        $childrenHtml = '';
        foreach ($this->keyedChildren as $keyed) {
            $childrenHtml .= $keyed->node->toHtml();
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

        foreach ($this->keyedChildren as $keyed) {
            $found = $keyed->node->findByPath($targetPath);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Compare this node with another and generate patches.
     * Uses Levenshtein algorithm for efficient list diffing.
     */
    public function compare(DomNode $other, Patcher $patcher): void
    {
        // If other is not a ListDomNode or tag differs, replace entirely.
        // Unbind the old subtree's listeners and bind the new one's.
        if (!$other instanceof ListDomNode || $this->tag !== $other->tag) {
            $other->unbindEvents();
            $patcher->replaceNode($this->path, $this);
            $this->bindEvents();
            return;
        }

        // Compare attributes
        $thisAttrs = $this->attributes;
        $otherAttrs = $other->attributes;

        foreach ($thisAttrs as $name => $value) {
            if (!isset($otherAttrs[$name]) || $otherAttrs[$name] !== $value) {
                $patcher->setAttribute($this->path, $name, $value);
            }
        }

        foreach ($otherAttrs as $name => $value) {
            if (!isset($thisAttrs[$name])) {
                $patcher->removeAttribute($this->path, $name);
            }
        }

        // Compare classes
        if ($this->classes !== $other->classes) {
            $patcher->setAttribute($this->path, 'class', implode(' ', array_unique($this->classes)));
        }

        // This list element survives — diff its own listeners.
        $this->diffEvents($other);

        // Use Levenshtein for keyed children comparison
        $keyFn = fn(KeyedDomNode $item) => $item->key;

        $diff = Levenshtein::diff($other->keyedChildren, $this->keyedChildren, $keyFn);

        // Process diff operations in reverse order (as Levenshtein returns them backwards)
        $diff = array_reverse($diff);

        $newIndex = 0;
        $removeOffset = 0;

        foreach ($diff as [$action, $oldItem, $newItem]) {
            switch ($action) {
                case Levenshtein::SKIP:
                    // Same key - compare the nodes recursively
                    if ($oldItem !== null && $newItem !== null) {
                        $newItem->node->compare($oldItem->node, $patcher);
                    }
                    $newIndex++;
                    break;

                case Levenshtein::SUBSTITUTE:
                    // Key changed - replace node at index: old listeners go,
                    // new ones bind.
                    if ($newItem !== null) {
                        $oldItem?->node->unbindEvents();
                        $patcher->updateAt($this->path, $newIndex, $newItem->node);
                        $newItem->node->bindEvents();
                    }
                    $newIndex++;
                    break;

                case Levenshtein::INSERT:
                    // New item inserted at index
                    if ($newItem !== null) {
                        $patcher->insertAt($this->path, $newIndex, $newItem->node);
                        $newItem->node->bindEvents();
                    }
                    $newIndex++;
                    break;

                case Levenshtein::DELETE:
                    // Item removed - use adjusted index accounting for previous removals
                    $oldItem?->node->unbindEvents();
                    $patcher->removeAt($this->path, $newIndex + $removeOffset);
                    $removeOffset++;
                    break;
            }
        }
    }
}
