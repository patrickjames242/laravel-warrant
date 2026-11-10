<?php

namespace Warrant\DSL\Compiling\Concerns;

use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Warrant\DSL\Compiling\AliasScope;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;

/**
 * How {@see RuleSetCompiler} turns an argument written in rule text into the
 * value a condition or handle receives: a literal, a `@context` value, or a
 * `@column` or `@sql` expression.
 *
 * @internal
 */
trait ResolvesArguments
{
    /**
     * Resolve one symbolic DSL argument to its concrete value for compilation.
     * A {@see ContextRef} is filled from the check-time context (absent → null); a
     * {@see ColumnRef} becomes a grammar-wrapped {@see Expression} qualified by the
     * frame it was written in; a {@see SqlRef} an {@see Expression} of its body; a
     * {@see LiteralNode} its value. An expanded rule set holds no binding, so any
     * other node is rejected. Shared by condition parameters and the
     * cross-schema handle row selector / `with` map so all three resolve
     * identically — and all three against the *enclosing* frame, which is where
     * their text was written.
     */
    private function resolveArgValue(INode $argument, CompilationContext $ctx): mixed
    {
        /* Only an analysis reads one, in place of an argument the text is missing,
           and the text then has a syntax error that a parse to compile throws. */
        if ($argument instanceof MissingArgumentNode) {
            throw new InvalidArgumentException(
                'A rule with a missing argument cannot be compiled; the text it was read from has a syntax error.'
            );
        }

        if ($argument instanceof ContextRef) {
            return $ctx->checkContext[$argument->key->name] ?? null;
        }

        if ($argument instanceof ColumnRef) {
            return $this->resolveColumnRef($argument, $ctx);
        }

        if ($argument instanceof SqlRef) {
            // Always parenthesize (even if the author already did): a bare
            // `select ...` is then valid as a scalar subquery in a comparison.
            return new Expression('(' . $this->resolveArgValue($argument->sql, $ctx) . ')');
        }

        if ($argument instanceof LiteralNode) {
            return $argument->value;
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported argument node [%s]; compile a rule set expanded by %s.',
            $argument::class,
            Expander::class,
        ));
    }

    /**
     * Resolve a `@column [<name>.]<column>` reference to an {@see Expression} of the
     * grammar-wrapped `<qualifier>.<column>` identifier (e.g.
     * `` `timesheets`.`pay_period_id` ``), quoted with the query's own grammar so
     * it is emitted verbatim, never re-wrapped or bound as a value.
     *
     * The qualifier comes from the frame the reference was written in — see
     * {@see AliasScope} — and not from the registry, because the same rule text
     * compiles against a different table each time it is reached through a
     * differently-aliased hop. A name the frame does not bind is an author's
     * mistake and throws; that the *named* table really is in scope in the
     * surrounding SQL is now something the scope guarantees, rather than being left
     * to the author and a SQL error at execution.
     */
    private function resolveColumnRef(ColumnRef $ref, CompilationContext $ctx): Expression
    {
        $qualifier = $this->aliases($ctx)->resolveColumn($ref);

        return $ctx->queries->wrap($qualifier . '.' . $ref->column->name);
    }

    /**
     * Resolve a whole argument list, or answer null when one of them cannot be
     * resolved *here at all*: a `@column` naming a row this frame has no `from`
     * for. That is not an author's mistake — the same rule works where a row is in
     * scope — so it is not an error either; the leaf holding it simply cannot be
     * evaluated, exactly like a row condition with no row, and its caller folds it
     * to `false`. (A name the frame does not bind at all *is* a mistake, and
     * {@see AliasScope::resolve()} still throws for it.)
     *
     * @param list<INode> $arguments
     * @return array<int, mixed>|null
     */
    private function resolveArgValues(array $arguments, CompilationContext $ctx): ?array
    {
        $resolved = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof ColumnRef && $this->aliases($ctx)->resolveColumn($argument) === null) {
                return null;
            }

            $resolved[] = $this->resolveArgValue($argument, $ctx);
        }

        return $resolved;
    }
}
