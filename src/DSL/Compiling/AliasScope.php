<?php

namespace Warrant\DSL\Compiling;

use InvalidArgumentException;

/**
 * The names a `@column` reference may use at one point in a compile, and the SQL
 * qualifier each of them stands for.
 *
 * A rule is written once and compiled at whatever depth it is reached, so the text
 * `@column documents.id` cannot mean a fixed table: reached through
 * `can(view for documents(…) as d2)` it has to come out as `d2.id`, and reached at
 * the top of a query the caller aliased as `documents as d` it has to come out as
 * `d.id`. This is the map that answers that question, derived on the way down and
 * carried on {@see CompilationContext} beside the {@see CallStack}.
 *
 * ## What a name is
 *
 * A frame's own row is named by its schema key, which is the only name the rules
 * of that schema can possibly know — their author cannot see who reached them.
 * `as <alias>` supplies a second name for the same row, for the one case where the
 * text *can* see both: a `check(...)` predicate, written inline in the enclosing
 * rule. Which is why the two descents differ:
 *
 *  - {@see enteringRuleSet} — a `can(...)` hop, which compiles *another rule set*.
 *    The child scope is fresh: that text can name its own schema key and nothing
 *    else, so binding anything further would only let a typo compile.
 *  - {@see enteringPredicate} — a `check(...)` hop, whose predicate belongs to the
 *    enclosing text. The parent's names stay in scope, so the predicate can
 *    correlate back to the outer row, and the new frame is added on top.
 *
 * ## Shadowing
 *
 * The same rule as SQL: the closest binding wins. An unaliased hop binds the
 * target's schema key over any outer binding of that key, so a self-referencing
 * `check(… for documents(…))` reads `@column documents.id` as the *inner* row.
 * Naming the inner frame instead — `as d2` — leaves `documents` meaning the outer
 * row and makes both reachable.
 *
 * ## Names and identifiers
 *
 * A name is the author's and an identifier is the compiler's; {@see $bindings} is
 * the map between them. Every frame's identifier has to differ from every other
 * frame's, or a predicate could not refer to one frame from inside another — so
 * {@see freeQualifier()} takes the name the author asked for and suffixes it only
 * when that identifier is already spoken for, keeping their word as the base.
 *
 * {@see $usedQualifiers} is what it consults, and unlike {@see $bindings} it
 * survives {@see enteringRuleSet()}: another schema's rule text starts with a
 * fresh set of *names*, but its rows are selected into the same query, where the
 * *identifiers* are still taken.
 *
 * The suffixed form is never something an author types. A `check(… as d2)` binds
 * the name `d2`, and `@column d2.x` resolves through it to whichever identifier
 * that frame was given.
 *
 * ## A null qualifier
 *
 * A name may be bound to null, which means the row is *known but not in scope* —
 * an untargeted compile, or the unbound branch of a hop, where there is no `from`
 * for a column reference to hang on. That is a different thing from a name nobody
 * bound, which is an author's mistake and throws. Null is what lets the compiler
 * fold such a reference away instead of emitting SQL about a table that isn't
 * there.
 *
 * Immutable, so a scope is path-scoped for free: each descent derives its own copy
 * and a sibling branch never sees it.
 */
final readonly class AliasScope
{
    /**
     * @param array<string, ?string> $bindings Name → the SQL qualifier it stands
     *   for, null when that row is not in scope. Later keys shadow earlier ones.
     * @param string|null $current The qualifier for *this* frame's own row — what
     *   an unqualified `@column <column>` means, and the table a row condition
     *   builds its predicate against.
     * @param list<string> $usedQualifiers Every identifier already standing for a
     *   frame in the query being built. See the class docblock for why this
     *   outlives the names in {@see $bindings}.
     */
    private function __construct(
        public array $bindings = [],
        public ?string $current = null,
        public array $usedQualifiers = [],
    ) {
    }

    /**
     * The scope a top-level compile starts from: one name, the compiled schema's
     * own key, standing for the row the caller's query is already selecting.
     */
    public static function root(string $schemaKey, ?string $qualifier): self
    {
        return new self([$schemaKey => $qualifier], $qualifier, $qualifier === null ? [] : [$qualifier]);
    }

    /**
     * The empty scope, naming nothing. What a schema with no model at all gets: a
     * capability schema has no rows, so there is no qualifier to bind its key to,
     * and a `@column` naming it is an author's mistake rather than a row out of
     * scope.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Descend into another rule set — a `can(...)` hop. The child scope is fresh:
     * see the class docblock for why the target's own key is the only name in it.
     */
    public function enteringRuleSet(string $schemaKey, ?string $qualifier): self
    {
        return new self([$schemaKey => $qualifier], $qualifier, $this->including($qualifier));
    }

    /**
     * Descend into a predicate written in the *enclosing* text — a `check(...)`
     * hop. This scope's names carry over so the predicate can correlate back to
     * them, and the new frame is bound under its alias when it has one, else under
     * the target's schema key (shadowing any outer binding of it).
     */
    public function enteringPredicate(string $schemaKey, ?string $alias, ?string $qualifier): self
    {
        return new self(
            array_merge($this->bindings, [$alias ?? $schemaKey => $qualifier]),
            $qualifier,
            $this->including($qualifier),
        );
    }

    /**
     * Descend into a hop whose target has no rows at all — a capability schema,
     * which has no table for a frame to be selected from. The counterparts to
     * {@see enteringRuleSet} and {@see enteringPredicate} for that target, and they
     * take no arguments because there is nothing to take: no frame to name, and no
     * qualifier to name it with.
     *
     * Both bind nothing. A key bound to null would say the target's rows are out
     * of scope here, and null is the answer that folds — but the target has no
     * rows anywhere, in any compile, reached from anywhere, so naming it is a
     * mistake to report rather than a reference to fold. {@see $current} is null
     * for the same reason: a bare `@column` in such a frame is about nothing.
     *
     * The identifiers already spoken for carry over, as they do for any descent.
     * A target with no table selects nothing and so frees nothing, and the frames
     * around it are still in the query being built.
     *
     * The two differ exactly as their row-bearing counterparts do: a predicate
     * belongs to the enclosing text and keeps its names, so it can correlate back
     * to the frame it was written in; another schema's rule set starts fresh,
     * because its author cannot see who reached it.
     */
    public function enteringRowlessPredicate(): self
    {
        return new self($this->bindings, null, $this->usedQualifiers);
    }

    /** {@see enteringRowlessPredicate} — the `can(...)` half, starting fresh. */
    public function enteringRowlessRuleSet(): self
    {
        return new self([], null, $this->usedQualifiers);
    }

    /**
     * An identifier the emitted SQL can give a new frame without colliding with
     * one already in the query: the name asked for, or that name with the lowest
     * free numeric suffix.
     */
    public function freeQualifier(string $preferred): string
    {
        if (! in_array($preferred, $this->usedQualifiers, true)) {
            return $preferred;
        }

        for ($suffix = 1;; $suffix++) {
            $candidate = $preferred.'_'.$suffix;

            if (! in_array($candidate, $this->usedQualifiers, true)) {
                return $candidate;
            }
        }
    }

    /**
     * This scope's identifiers plus $qualifier, which a frame with no rows in
     * scope does not have.
     *
     * @return list<string>
     */
    private function including(?string $qualifier): array
    {
        if ($qualifier === null || in_array($qualifier, $this->usedQualifiers, true)) {
            return $this->usedQualifiers;
        }

        return [...$this->usedQualifiers, $qualifier];
    }

    /**
     * The SQL qualifier a `@column` reference should be emitted against: this
     * frame's own row for an unqualified reference (a null $name), else the named
     * binding. A null *return* means the row is not in scope — see the class
     * docblock; a name nobody bound is an author error and throws.
     *
     * @throws InvalidArgumentException When $name is not in scope.
     */
    public function resolve(?string $name): ?string
    {
        if ($name === null) {
            return $this->current;
        }

        if (! array_key_exists($name, $this->bindings)) {
            throw new InvalidArgumentException(sprintf(
                'A @column reference names [%s], which is not in scope here; %s',
                $name,
                $this->bindings === []
                    ? 'no table is in scope at this point.'
                    : sprintf('the names in scope are [%s].', implode(', ', $this->names())),
            ));
        }

        return $this->bindings[$name];
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->bindings);
    }

    /**
     * Every name in scope, innermost binding last.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->bindings);
    }
}
