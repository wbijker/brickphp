<?php

namespace BrickPHP\UI;

/**
 * Fluent builder for CSS modifier prefixes applied to a single style:
 * pseudo-classes, pseudo-elements, breakpoints, color schemes, and
 * relational/parameterized selectors.
 *
 * Static factories start a chain; instance methods extend it. Pseudo-classes,
 * breakpoints, color scheme, and relational selectors compose freely; only
 * one pseudo-element is allowed per Pseudo (the latest call wins).
 *
 *   ->background(Color::gray(), Pseudo::hover())
 *   ->background(Color::gray(), Pseudo::hover()->active()->sm())
 *   ->color(Color::neutral(400), Pseudo::placeholder())
 *
 * @method static static sm() @method static static md() @method static static lg()
 * @method static static xl() @method static static xxl()
 * @method static static dark() @method static static light()
 * @method static static hover() @method static static active() @method static static focus()
 * @method static static focusVisible() @method static static focusWithin() @method static static visited()
 * @method static static disabled() @method static static enabled() @method static static checked()
 * @method static static required() @method static static valid() @method static static invalid()
 * @method static static first() @method static static last() @method static static only()
 * @method static static odd() @method static static even() @method static static empty()
 * @method static static placeholder()
 * @method static static has(Selector $selector)
 * @method static static not(Selector $selector)
 * @method static static nthChild(string $expr)
 * @method static sm() @method static md() @method static lg()
 * @method static xl() @method static xxl()
 * @method static dark() @method static light()
 * @method static hover() @method static active() @method static focus()
 * @method static focusVisible() @method static focusWithin() @method static visited()
 * @method static disabled() @method static enabled() @method static checked()
 * @method static required() @method static valid() @method static invalid()
 * @method static first() @method static last() @method static only()
 * @method static odd() @method static even() @method static empty()
 * @method static placeholder()
 * @method static has(Selector $selector)
 * @method static not(Selector $selector)
 * @method static nthChild(string $expr)
 */
class Pseudo
{
    private ?Breakpoint $breakpoint = null;
    private ?ColorScheme $colorScheme = null;
    /** @var string[] kebab-case pseudo tokens mapped by {@see \BrickPHP\Style\Utility} */
    private array $pseudoClasses = [];
    private ?string $pseudoElement = null;
    private ?string $hasSelector = null;
    /** @var string[] */
    private array $customSelectors = [];

    private const BREAKPOINTS = [
        'sm' => Breakpoint::Small,
        'md' => Breakpoint::Medium,
        'lg' => Breakpoint::Large,
        'xl' => Breakpoint::ExtraLarge,
        'xxl' => Breakpoint::TwoXL,
    ];

    private const SCHEMES = [
        'dark' => ColorScheme::Dark,
        'light' => ColorScheme::Light,
    ];

    private const PSEUDO_CLASSES = [
        'hover'        => 'hover',
        'active'       => 'active',
        'focus'        => 'focus',
        'focusVisible' => 'focus-visible',
        'focusWithin'  => 'focus-within',
        'visited'      => 'visited',
        'disabled'     => 'disabled',
        'enabled'      => 'enabled',
        'checked'      => 'checked',
        'required'     => 'required',
        'valid'        => 'valid',
        'invalid'      => 'invalid',
        'first'        => 'first',
        'last'         => 'last',
        'only'         => 'only',
        'odd'          => 'odd',
        'even'         => 'even',
        'empty'        => 'empty',
    ];

    private const PSEUDO_ELEMENTS = [
        'placeholder' => 'placeholder',
    ];

    public function __call(string $name, array $args): static
    {
        return $this->apply($name, $args);
    }

    public static function __callStatic(string $name, array $args): static
    {
        return (new static())->apply($name, $args);
    }

    private function apply(string $name, array $args): static
    {
        if (isset(self::BREAKPOINTS[$name])) {
            $this->breakpoint = self::BREAKPOINTS[$name];
            return $this;
        }

        if (isset(self::SCHEMES[$name])) {
            $this->colorScheme = self::SCHEMES[$name];
            return $this;
        }

        if (isset(self::PSEUDO_CLASSES[$name])) {
            $this->pseudoClasses[] = self::PSEUDO_CLASSES[$name];
            return $this;
        }

        if (isset(self::PSEUDO_ELEMENTS[$name])) {
            $this->pseudoElement = self::PSEUDO_ELEMENTS[$name];
            return $this;
        }

        if ($name === 'has' && isset($args[0]) && $args[0] instanceof Selector) {
            $this->hasSelector = $args[0]->toSelector();
            return $this;
        }

        if ($name === 'not' && isset($args[0]) && $args[0] instanceof Selector) {
            $this->customSelectors[] = 'not-[' . $args[0]->toSelector() . ']';
            return $this;
        }

        if ($name === 'nthChild' && isset($args[0]) && is_string($args[0])) {
            $this->customSelectors[] = 'nth-[' . $args[0] . ']';
            return $this;
        }

        throw new \BadMethodCallException("Pseudo::$name() is not defined");
    }

    /**
     * Build the class-name prefix (with trailing colon). Empty string when
     * no modifiers are set — callers can prepend unconditionally.
     */
    public function prefix(): string
    {
        $parts = [];

        if ($this->breakpoint !== null) {
            $parts[] = $this->breakpoint->value;
        }

        if ($this->colorScheme !== null) {
            $parts[] = $this->colorScheme->value;
        }

        if ($this->hasSelector !== null) {
            $parts[] = 'has-[' . $this->hasSelector . ']';
        }

        foreach ($this->customSelectors as $sel) {
            $parts[] = $sel;
        }

        foreach ($this->pseudoClasses as $pc) {
            $parts[] = $pc;
        }

        if ($this->pseudoElement !== null) {
            $parts[] = $this->pseudoElement;
        }

        return $parts ? implode(':', $parts) . ':' : '';
    }

    public function __toString(): string
    {
        return $this->prefix();
    }
}
