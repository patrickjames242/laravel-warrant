<?php

namespace Warrant\Builders;

use LogicException;
use Warrant\Builders\Concerns\TargetsSchema;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * A cross-schema condition check — `check(<predicate> for <handle> [as <alias>]
 * [with <map>])` — made by {@see Build::check()}: a question delegated to
 * another schema, answered against that schema's own vocabulary.
 *
 * ```php
 * Build::check('is_open')->for('pay_periods', Ref::column('pay_period_id'))
 * ```
 *
 * Its condition terms are the predicate. That tree is read against the target,
 * so a `can(...)` in it names one of the target's abilities and takes no
 * `for`. It may hold no `check(...)` of its own. Both hold however the term is
 * added: directly, in a group or as raw text.
 * On a `check(...)` the alias is
 * also a name the predicate itself can use, which is how a predicate spanning
 * two frames of one table tells them apart.
 *
 * A check needs {@see for()}, and a predicate of at least one term: unlike a
 * group an empty predicate cannot fold to `false`, because a `check(...)`
 * predicate may not contain a constant.
 */
final class CrossSchemaCheckBuilder extends WarrantConditionBuilder
{
    use TargetsSchema;

    /**
     * Throws when the check cannot be made, so the mistake is reported where
     * the check is written.
     */
    public function assertComplete(): static
    {
        if ($this->schemaKey === null) {
            throw new LogicException(
                'A check(...) needs the schema it asks; call for() on it with the target schema.'
            );
        }

        if (! $this->hasTerms()) {
            throw new LogicException(
                'A check(...) predicate cannot be empty; add at least one condition to the check. '
                .'Unlike a group it cannot fall back to false — a check(...) predicate may only ask the target schema\'s conditions.'
            );
        }

        return $this;
    }

    public function toNode(SyntaxSource $source): CrossSchemaConditionNode
    {
        $this->assertComplete();

        $predicate = $this->buildConditions($source);
        $this->assertAsksOnlyTarget($predicate);

        return new CrossSchemaConditionNode(
            $this->schemaKeyNode($source),
            $predicate,
            $this->isRowBound,
            $this->keyArgumentNodes($source),
            $this->withEntryNodes($source),
            $this->aliasNode($source),
            self::location($source),
        );
    }

    /**
     * Throws when $predicate holds a `check(...)` or a `can(... for ...)`. A
     * predicate sees two frames at most, the rule's own and the one this check
     * names, and asks only the schema this check names.
     */
    private function assertAsksOnlyTarget(?IBooleanExpressionNode $predicate): void
    {
        if ($predicate instanceof NotNode) {
            $this->assertAsksOnlyTarget($predicate->operand);
        } elseif ($predicate instanceof AndNode || $predicate instanceof OrNode) {
            $this->assertAsksOnlyTarget($predicate->leftSide);
            $this->assertAsksOnlyTarget($predicate->rightSide);
        } elseif ($predicate instanceof CrossSchemaConditionNode) {
            throw new LogicException(sprintf(
                'A check(...) may not be written inside the predicate of the check(...) for schema [%s]; '
                    .'ask it from a derived condition of schema [%s] instead.',
                $this->schemaKey,
                $this->schemaKey,
            ));
        } elseif ($predicate instanceof CrossSchemaCanNode && $predicate->schemaKey !== null) {
            throw new LogicException(sprintf(
                'A can(...) inside the predicate of the check(...) for schema [%s] may not name a schema with for; '
                    .'write can(<ability>) to ask schema [%s] about one of its own abilities.',
                $this->schemaKey,
                $this->schemaKey,
            ));
        }
    }
}
