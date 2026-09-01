<?php

namespace Warrant\Builders;

use Closure;
use LogicException;
use Warrant\Rules\CannotClause;
use Warrant\Rules\WarrantRule;
use Warrant\Schema\WarrantDenialContext;

/**
 * A fluent, Laravel-query-builder-style front-end for constructing a whole
 * {@see WarrantRule} in PHP instead of the string DSL.
 *
 * It extends {@see WarrantConditionBuilder} with the clause half of a rule
 * (`theyCan` / `theyCannot` / `theyCannotBecause`) and finalization via
 * `toRule()`. The condition methods it inherits return `static`, so a top-level
 * chain keeps the rule builder — `->if(...)->theyCan(...)` works — while a group
 * closure only ever receives a bare condition builder.
 *
 * ```php
 * WarrantRule::build()
 *     ->if('is_self')
 *     ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
 *     ->theyCan('view', 'update')
 *     ->theyCannotBecause('delete', 'This record is locked.')
 *     ->toRule();
 * ```
 */
final class WarrantRuleBuilder extends WarrantConditionBuilder
{
    /** @var list<string> */
    private array $can = [];

    /** @var list<CannotClause> */
    private array $cannotClauses = [];

    // -- clauses --------------------------------------------------------------

    public function theyCan(string ...$abilities): static
    {
        $this->can = [...$this->can, ...$abilities];

        return $this;
    }

    public function theyCannot(string ...$abilities): static
    {
        $this->cannotClauses[] = new CannotClause($abilities);

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
        $this->cannotClauses[] = new CannotClause(is_array($abilities) ? array_values($abilities) : [$abilities], $message);

        return $this;
    }

    // -- materialization ------------------------------------------------------

    public function toRule(): WarrantRule
    {
        if ($this->can === [] && $this->cannotClauses === []) {
            throw new LogicException(
                "A rule needs at least one 'they can ...' or 'they cannot ...' clause; call theyCan(), theyCannot(), or theyCannotBecause() before toRule()."
            );
        }

        return new WarrantRule($this->buildConditions(), $this->can, $this->cannotClauses);
    }
}
