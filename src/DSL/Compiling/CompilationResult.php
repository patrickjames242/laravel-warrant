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
 * without ever consulting a row, and reading that outcome is what lets a boolean
 * check skip the database entirely. It is a {@see Decision} rather than a
 * `?bool`, because a compile can settle on any of SQL's three truth values and
 * "unknown" must not be confused with either `false` or "ask the database".
 * Materializing through {@see toQuery()} erases the distinction, because a
 * spliceable predicate has to spell a constant out as `1 = 1` / `1 = 0` / `null`.
 *
 * Folding is done once and cached. That is not only for speed: folding twice
 * yields two output builders that share the same leaf {@see Builder} instances
 * by reference, since a nested where stores the query itself and a single-clause
 * leaf is copied into its parent wholesale. Two callers each splicing "their"
 * predicate into a different host would then be splicing the same objects.
 */
final class CompilationResult
{
    /** The folded compile, or null while it has not been folded yet. */
    private Decision|Builder|null $folded = null;

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
     * What the rules settled outright, or {@see Decision::NeedsQuery} when they
     * did not and the predicate has to be asked in SQL — which is not only the
     * row-dependent case: a global condition emitting SQL is the same for every
     * row and is still not a constant here.
     *
     * Both {@see Decision::False} and {@see Decision::Unknown} mean no access, so
     * a caller wanting a plain yes/no can read {@see Decision::grants()} and
     * ignore which of the two it was.
     */
    public function decision(): Decision
    {
        $folded = $this->fold();

        return $folded instanceof Builder ? Decision::NeedsQuery : $folded;
    }

    /**
     * The predicate as a detached query, ready to splice.
     *
     * A compile that folded to a constant is spelled out here, because
     * {@see \Illuminate\Database\Query\Builder::addNestedWhereQuery()} skips a
     * query holding no where clause — an always-true predicate has to say so out
     * loud or it would silently vanish. An unknown is spelled `null`, which is the
     * same value it already was: never true, so it selects no row, and `not null`
     * is null again, so it cannot be negated into selecting one.
     */
    public function toQuery(): Builder
    {
        $folded = $this->fold();

        if ($folded instanceof Builder) {
            return $folded;
        }

        return $this->queries->newQuery()->whereRaw(match ($folded) {
            Decision::True => '1 = 1',
            Decision::False => '1 = 0',
            Decision::Unknown => 'null',
        });
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

    private function fold(): Decision|Builder
    {
        return $this->folded ??= $this->foldNode();
    }

    private function foldNode(): Decision|Builder
    {
        $built = $this->node->buildWhereClause($this->queries);

        return $built instanceof Builder ? $built : Decision::forConstant($built);
    }
}
