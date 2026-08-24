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
     */
    public function __construct(
        public Authenticatable $user,
        public ?string $targetSqlId,
        public array $checkContext,
        public string $boolean = 'and',
        public bool $negate = false,
    ) {
    }

    /**
     * Derive a copy that attaches its predicate under a different connector.
     */
    public function withBoolean(string $boolean): self
    {
        return new self(
            $this->user,
            $this->targetSqlId,
            $this->checkContext,
            $boolean,
            $this->negate,
        );
    }

    /**
     * Derive a copy with the negation flag toggled (crossing a `not`).
     */
    public function negated(): self
    {
        return new self(
            $this->user,
            $this->targetSqlId,
            $this->checkContext,
            $this->boolean,
            ! $this->negate,
        );
    }
}
