<?php

namespace Warrant\Builders;

use LogicException;
use Warrant\Builders\Concerns\TargetsSchema;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
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
 * so a `can(...)` in it names one of the target's abilities and a nested
 * `check(...)` starts from the target's frame. On a `check(...)` the alias is
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

        return new CrossSchemaConditionNode(
            $this->schemaKeyNode($source),
            $this->buildConditions($source),
            $this->isRowBound,
            $this->keyArgumentNodes($source),
            $this->withEntryNodes($source),
            $this->aliasNode($source),
            self::location($source),
        );
    }
}
