<?php

namespace BrickPHP\State;

use BrickPHP\VNode\StateRef;

/**
 * State that belongs to the application rather than to any one component —
 * reachable from every tree without being handed down, and restored and saved
 * on the same schedule as component state.
 *
 * The app declares it once, in {@see \BrickPHP\VNode\App::registerGlobalState()},
 * by binding static properties:
 *
 *   class Session
 *   {
 *       public static int $userId = 0;
 *       public static string $name = '';
 *   }
 *
 *   protected function registerGlobalState(GlobalStateManager $manager): void
 *   {
 *       $manager->useState(Session::$userId);
 *       $manager->useState(Session::$name);
 *   }
 *
 * Any component then reads `Session::$name` directly — typed, resolved at
 * compile time, no key to spell and nothing to inject. Assigning to it is a
 * normal assignment; the value is written back to storage when the request
 * finishes.
 *
 * Registration order is the contract: the values are stored as one indexed
 * list, exactly as a component's own state is, so a stored list whose length
 * no longer matches the registrations is ignored rather than smeared across
 * the wrong properties. Adding or removing a binding therefore resets the set
 * to its declared defaults once, which is the same trade component state makes.
 *
 * Give every bound property a default. A typed static with no value cannot even
 * be passed by reference — PHP rejects it before the framework sees it — and
 * the defaults are what a first visit, or a reset after a shape change, falls
 * back to.
 */
final class GlobalStateManager
{
    /**
     * Storage key for the whole set. One entry, one list of values: the app's
     * globals are a single unit, and the components that read them never name it.
     */
    private const KEY = '@global';

    /** @var StateRef[] Bound properties, in registration order. */
    private array $refs = [];

    public function __construct(private StateManager $state) {}

    /**
     * Bind a static property as global state. Mirrors
     * {@see \BrickPHP\VNode\Component::useState()}: the reference is held, so
     * restoring writes straight through to the property and reads see live
     * values.
     *
     * @param class-string<State>|null $class Optional. Coerce restored values
     *   through `Class::deserialize()` — see {@see Component::useState()}.
     */
    public function useState(mixed &$ref, ?string $class = null): void
    {
        $this->refs[] = new StateRef($ref, $class, is_array($ref));
    }

    /** Fill every bound property from storage. Called before the first render. */
    public function restore(): void
    {
        if ($this->refs === []) {
            return;
        }
        $saved = $this->state->getState(self::KEY);
        // A different number of bindings than was stored means the app's shape
        // changed since; keep the declared defaults rather than guess.
        if (count($saved) !== count($this->refs)) {
            return;
        }

        foreach (array_values($saved) as $i => $value) {
            $entry = $this->refs[$i];
            if ($entry->class !== null && is_subclass_of($entry->class, State::class)) {
                $class = $entry->class;
                $value = $entry->isArray
                    ? array_map(fn($v) => $class::deserialize($v), is_array($value) ? $value : [])
                    : ($value === null ? null : $class::deserialize($value));
            }
            $entry->ref = $value;
        }
    }

    /** Write every bound property back to storage. */
    public function save(): void
    {
        if ($this->refs === []) {
            return;
        }
        $this->state->saveState(self::KEY, array_map(fn(StateRef $r) => $r->ref, $this->refs));
    }

    /** How many properties are bound — for diagnostics and the debug panel. */
    public function count(): int
    {
        return count($this->refs);
    }
}
