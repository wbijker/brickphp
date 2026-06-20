<?php

namespace BrickPHP\UI;

use BrickPHP\State\StateManager;
use BrickPHP\VNode\RenderPhase;
use BrickPHP\VNode\VNode;

/**
 * Select dropdown element.
 *
 * Options (and the form attributes below) are applied straight onto the
 * underlying dom node so they survive the VNode render pass: the live pipeline
 * calls {@see render()}, which returns the dom node directly and never calls
 * {@see build()}. Re-deriving the option children on every render (clearing
 * first) keeps the element idempotent across patches.
 */
class Select extends UIElement
{
    /** @var (Option|Optgroup)[] */
    protected array $options = [];

    public function __construct()
    {
        parent::__construct('select');
    }

    public function name(string $name): static
    {
        $this->dom()->attr('name', $name);
        return $this;
    }

    public function id(string $id): static
    {
        $this->dom()->attr('id', $id);
        return $this;
    }

    public function required(bool $required = true): static
    {
        if ($required) {
            $this->dom()->attr('required', 'required');
        }
        return $this;
    }

    public function disabled(bool $disabled = true): static
    {
        if ($disabled) {
            $this->dom()->attr('disabled', 'disabled');
        }
        return $this;
    }

    public function multiple(bool $multiple = true): static
    {
        if ($multiple) {
            $this->dom()->attr('multiple', 'multiple');
        }
        return $this;
    }

    /**
     * Number of visible rows (HTML `size` attribute). Renamed from size() to
     * avoid clashing with UIElement::size() (the width/height utility).
     */
    public function visibleRows(int $rows): static
    {
        $this->dom()->attr('size', (string)$rows);
        return $this;
    }

    public function autocomplete(string $value): static
    {
        $this->dom()->attr('autocomplete', $value);
        return $this;
    }

    /**
     * Bind a component property to this select's value.
     * The property will be hydrated with the frontend value on each request.
     */
    public function bind(string &$ref): static
    {
        $this->dom()->bindRef($ref);
        return $this;
    }

    public function options(Option|Optgroup ...$options): static
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    public function build(): DomNode
    {
        $this->syncOptions();
        return parent::build();
    }

    public function render(StateManager $state, ?VNode $parent = null, RenderPhase $phase = RenderPhase::Initial): DomNode
    {
        $this->syncOptions();
        return parent::render($state, $parent, $phase);
    }

    /** (Re)build the <option>/<optgroup> children on the dom node. */
    private function syncOptions(): void
    {
        $this->dom()->clearChildren();
        foreach ($this->options as $option) {
            $this->dom()->children($option->toNode());
        }
    }
}
