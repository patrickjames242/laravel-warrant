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
 * row and makes both reachable. Nothing is renamed behind the author's back and
 * nothing is auto-generated: an unaliased hop emits no `as` in its SQL either, so
 * the shadowing in the emitted query is exactly the shadowing in the scope.
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
     */
    private function __construct(
        public array $bindings = [],
        public ?string $current = null,
    ) {
    }

    /**
     * The scope a top-level compile starts from: one name, the compiled schema's
     * own key, standing for the row the caller's query is already selecting.
     */
    public static function root(string $schemaKey, ?string $qualifier): self
    {
        return new self([$schemaKey => $qualifier], $qualifier);
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
        return new self([$schemaKey => $qualifier], $qualifier);
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
        );
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
