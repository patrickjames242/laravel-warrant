<?php

namespace Warrant\DSL\Compiling;

use LogicException;

/**
 * What a compile settled on: one of SQL's three truth values, or "ask the
 * database".
 *
 * A compile can settle on any of SQL's three truth values, and reach the third
 * in several ways: a row condition with no row, a `@column` about a table this
 * frame never selected, a row selector that resolved to nothing. None of those is
 * `false`; each is a question the compile could not answer, and the difference is
 * observable, because negating an answer is legitimate and negating "could not
 * tell" is not. Four cases keep that distinct from the fourth possibility, which
 * is not a truth value at all: that the answer has to be asked in SQL. See
 * {@see \Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode}.
 *
 * Only {@see True} grants. Both {@see False} and {@see Unknown} deny — a `WHERE`
 * clause keeps a row only when its predicate is true — so a caller that just
 * wants a yes/no reads {@see grants()} and does not have to think about which of
 * the two it got.
 */
enum Decision
{
    /** The predicate holds, for any row and without consulting one. */
    case True;

    /** The predicate does not hold, and that is a real answer. */
    case False;

    /**
     * The compile could not answer. Denies like {@see False}, but negates to
     * itself rather than to {@see True} — which is the whole reason it exists.
     */
    case Unknown;

    /**
     * The compile did not settle the answer, so the predicate has to be asked in
     * SQL.
     */
    case NeedsQuery;

    /**
     * The truth value as a where-clause operand: `true`, `false`, or `null` for
     * an unknown, ready to hand to
     * {@see \Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode::addAnd()}.
     *
     * @throws LogicException For {@see NeedsQuery}, which is not a truth value at
     *   all and has no operand — check for it before calling this.
     */
    public function asOperand(): ?bool
    {
        return match ($this) {
            self::True => true,
            self::False => false,
            self::Unknown => null,
            self::NeedsQuery => throw new LogicException(
                'Decision::NeedsQuery is not a constant and has no where-clause operand; '
                    .'splice the compiled query instead.',
            ),
        };
    }

    /**
     * Whether this outcome grants — true only for {@see True}. An unknown denies,
     * exactly as a false does.
     */
    public function grants(): bool
    {
        return $this === self::True;
    }

    /**
     * Whether the compile settled the answer without consulting a row.
     */
    public function isConstant(): bool
    {
        return $this !== self::NeedsQuery;
    }

    /**
     * The outcome for a folded constant, as
     * {@see \Warrant\DSL\Compiling\WhereClause\CompiledWhereClauseNode::buildWhereClause()}
     * reports one: a `bool`, or `null` for an unknown.
     */
    public static function forConstant(?bool $value): self
    {
        return match ($value) {
            true => self::True,
            false => self::False,
            null => self::Unknown,
        };
    }
}
