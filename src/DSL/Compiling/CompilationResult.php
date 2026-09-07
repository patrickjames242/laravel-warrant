<?php

namespace Warrant\DSL\Compiling;

use Illuminate\Database\Query\Builder;
use Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode;

/**
 * What one compile produced, in whichever form the caller needs it.
 *
 * The compiler used to answer this question twice, with a pair of methods per
 * unit: one returning the unmaterialized tree and one returning finished SQL.
 * The choice is not really the compiler's to make, though — it belongs to the
 * caller, and often to the caller's *next line*, since a check wants the folded
 * decision first and the SQL only if there wasn't one. So the compile happens
 * once and this offers the three views onto it.
 *
 * The important one is {@see decision()}. A rule set frequently settles a gate
 * without ever consulting a row, and reading that literal is what lets a boolean
 * check skip the database entirely. Materializing through {@see toQuery()}
 * erases it, because a spliceable predicate has to spell a constant out as
 * `1 = 1` / `1 = 0`.
 *
 * Folding is done once and cached. That is not only for speed: folding twice
 * yields two output builders that share the same leaf {@see Builder} instances
 * by reference, since a nested where stores the query itself and a single-clause
 * leaf is copied into its parent wholesale. Two callers each splicing "their"
 * predicate into a different host would then be splicing the same objects.
 */
final class CompilationResult
{
    private bool|Builder|null $folded = null;

    public function __construct(
        private readonly CompiledWhereClauseNode $node,
        private readonly QueryFactory $queries,
    ) {
    }

    /**
     * The unmaterialized tree, for a caller that wants to combine this compile
     * with another before anything becomes SQL — which is what keeps a constant
     * folding across an ability or cross-schema boundary.
     */
    public function node(): CompiledWhereClauseNode
    {
        return $this->node;
    }

    /**
     * The literal the rules settled on without consulting a row, or null when the
     * answer genuinely depends on one and SQL is needed.
     */
    public function decision(): ?bool
    {
        $folded = $this->fold();

        return is_bool($folded) ? $folded : null;
    }

    /**
     * The predicate as a detached query, ready to splice.
     *
     * A compile that folded to a literal is spelled out here as `1 = 1` / `1 = 0`,
     * because {@see \Illuminate\Database\Query\Builder::addNestedWhereQuery()}
     * skips a query holding no where clause — an always-true predicate has to say
     * so out loud or it would silently vanish.
     */
    public function toQuery(): Builder
    {
        $folded = $this->fold();

        return is_bool($folded)
            ? $this->queries->newQuery()->whereRaw($folded ? '1 = 1' : '1 = 0')
            : $folded;
    }

    /**
     * Attach the predicate to a host query as one parenthesized group, and return
     * the host.
     *
     * This is the only supported way to splice, and it always goes through
     * {@see toQuery()} so that a folded constant cannot be quietly dropped.
     */
    public function spliceInto(Builder $host): Builder
    {
        return $host->addNestedWhereQuery($this->toQuery());
    }

    private function fold(): bool|Builder
    {
        return $this->folded ??= $this->node->buildWhereClause($this->queries);
    }
}
