<?php

namespace Warrant\DSL\Compiling;

/**
 * The stack of layers {@see RuleSetCompiler} has descended through to reach the
 * point it is compiling now — abilities resolved through `can(...)`, cross-schema
 * `check(...)` dispatches, and conditions that expanded into further expressions.
 *
 * They are one stack rather than several because they genuinely interleave: an
 * ability's rule calls a condition, which expands into an expression containing a
 * `can(...)`, which resolves another schema's ability, whose condition calls
 * something else. Only the order they happened in explains how the compiler got
 * where it is, and that order is what an author needs when something recurses.
 *
 * Two guarantees ride on it, and they are deliberately different:
 *
 *  - An ability is **cycle-checked**. It takes no arguments, so re-entering one
 *    already on the stack means redoing work already in progress, which can only
 *    recur forever — rejected on sight, naming the loop.
 *  - Everything else is **counted**. A condition may legitimately recur with
 *    different arguments per level, and the compiler does not try to decide which
 *    recursions terminate, so the depth budget is the whole guard. The renderer
 *    still *reports* a repeating segment when it sees one; it just does not
 *    enforce against it.
 *
 * One budget covers the whole stack, because what it bounds is the size of the
 * SQL the compile will emit, and every layer adds to that whatever its kind.
 *
 * Immutable, so the stack is path-scoped for free: a call entered while descending
 * one branch can never leak into a sibling, since each descent derives its own
 * copy and the parent keeps the stack it had.
 */
final readonly class CallStack
{
    /**
     * Hard cap on nested compile layers.
     *
     * Cycle detection already guarantees that ability hops terminate; this bounds
     * everything, including the recursions no identity check can decide, and keeps
     * a legal but pathological chain from generating enormous nested SQL.
     */
    public const MAX_DEPTH = 64;

    /**
     * @param list<Call> $calls In call order, outermost first.
     */
    private function __construct(public array $calls = [])
    {
    }

    /**
     * The empty stack a top-level compile starts from.
     */
    public static function root(): self
    {
        return new self;
    }

    /**
     * Descend one layer, returning the deeper stack.
     *
     * @throws CrossSchemaCycleException When $call re-enters an ability already on
     *   the stack.
     * @throws CompileDepthException When the stack would exceed {@see MAX_DEPTH}.
     */
    public function enter(Call $call): self
    {
        $deeper = [...$this->calls, $call];

        if ($call->kind->isCycleChecked()) {
            foreach ($this->calls as $index => $existing) {
                if ($existing->repeats($call)) {
                    throw CrossSchemaCycleException::at($deeper, $index);
                }
            }
        }

        if (count($deeper) > self::MAX_DEPTH) {
            throw CompileDepthException::forStack($deeper, self::MAX_DEPTH);
        }

        return new self($deeper);
    }

    public function depth(): int
    {
        return count($this->calls);
    }

    public function isEmpty(): bool
    {
        return $this->calls === [];
    }

    /**
     * The stack rendered as a numbered trace, for an error message or a log.
     */
    public function render(): string
    {
        return CallStackTrace::render($this->calls);
    }
}
