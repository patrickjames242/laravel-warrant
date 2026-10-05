<?php

namespace Warrant\Builders;

use Closure;
use LogicException;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\Schema\WarrantDenialContext;

/**
 * A fluent, Laravel-query-builder-style front-end for constructing a whole
 * {@see WarrantRuleNode} in PHP instead of the string DSL.
 *
 * It extends {@see WarrantConditionBuilder} with the clause half of a rule
 * (`theyCan` / `theyCannot` / `theyCannotBecause`) and finalization via
 * `toRule()`. The condition methods it inherits return `static`, so a top-level
 * chain keeps the rule builder — `->if(...)->theyCan(...)` works — while a group
 * closure only ever receives a bare condition builder.
 *
 * ```php
 * WarrantRuleNode::build()
 *     ->if('is_self')
 *     ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
 *     ->theyCan('view', 'update')
 *     ->theyCannotBecause('delete', 'This record is locked.')
 *     ->toRule();
 * ```
 */
final class WarrantRuleBuilder extends WarrantConditionBuilder
{
    /** @var list<CanClauseNode> */
    private array $canClauses = [];

    /** @var list<CannotClauseNode> */
    private array $cannotClauses = [];

    // -- clauses --------------------------------------------------------------

    public function theyCan(string ...$abilities): static
    {
        $this->canClauses[] = new CanClauseNode(array_values($abilities));

        return $this;
    }

    public function theyCannot(string ...$abilities): static
    {
        $this->cannotClauses[] = new CannotClauseNode($abilities);

        return $this;
    }

    /**
     * Deny the given abilities with a denial message, surfaced when this clause is
     * the attributable cause of a singular-target denial. Each call adds one
     * clause, so calling it more than once gives different abilities different
     * messages. Pass a single ability as a string or several as an array; the
     * abilities in one call share the message.
     *
     * @param string|list<string> $abilities
     * @param string|Closure(WarrantDenialContext):(string|\Throwable) $message
     */
    public function theyCannotBecause(string|array $abilities, string|Closure $message): static
    {
        $this->cannotClauses[] = new CannotClauseNode(is_array($abilities) ? array_values($abilities) : [$abilities], $message);

        return $this;
    }

    // -- materialization ------------------------------------------------------

    public function toRule(): WarrantRuleNode
    {
        if ($this->canClauses === [] && $this->cannotClauses === []) {
            throw new LogicException(
                "A rule needs at least one 'they can ...' or 'they cannot ...' clause; call theyCan(), theyCannot(), or theyCannotBecause() before toRule()."
            );
        }

        return new WarrantRuleNode($this->buildConditions(), $this->canClauses, $this->cannotClauses);
    }
}
