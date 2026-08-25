<?php

namespace Warrant\RuleSyntaxTree;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The state {@see RuleSetCompiler} threads as it walks a boolean expression tree.
 *
 * It bundles the check-time invariants that never change during a single compile
 * (the user, the target SQL id, the effective check-time context bag) together
 * with the position-dependent state that a recursive step derives for its
 * children: the boolean connector a predicate attaches under, and whether the
 * current subtree is negated (flipped by De Morgan at each `not`).
 *
 * Immutable: the `with*` helpers return a modified copy, so a step can derive a
 * child context without disturbing its own.
 */
final readonly class CompilationContext
{
    /**
     * @param array<string, mixed> $checkContext The effective check-time context.
     * @param list<string> $visited The `(schema, ability)` frames on the current
     *   cross-schema compile path (each `"schemaKey\0ability"`), for cycle
     *   detection. Path-scoped: a frame added descending into a referenced schema
     *   never leaks to a sibling branch, since each step derives a fresh copy.
     */
    public function __construct(
        public Authenticatable $user,
        public ?string $targetSqlId,
        public array $checkContext,
        public string $boolean = 'and',
        public bool $negate = false,
        public array $visited = [],
    ) {
    }

    /**
     * Derive a copy that attaches its predicate under a different connector.
     */
    public function withBoolean(string $boolean): self
    {
        return new self(
            user: $this->user,
            targetSqlId: $this->targetSqlId,
            checkContext: $this->checkContext,
            boolean: $boolean,
            negate: $this->negate,
            visited: $this->visited,
        );
    }

    /**
     * Derive a copy with the negation flag toggled (crossing a `not`).
     */
    public function negated(): self
    {
        return new self(
            user: $this->user,
            targetSqlId: $this->targetSqlId,
            checkContext: $this->checkContext,
            boolean: $this->boolean,
            negate: ! $this->negate,
            visited: $this->visited,
        );
    }
}
