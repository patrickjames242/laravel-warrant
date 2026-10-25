<?php

namespace Warrant\Builders;

use Closure;
use LogicException;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\SyntaxSource;
use Warrant\Schema\Denials\WarrantDenialContext;

/**
 * A fluent, Laravel-query-builder-style front-end for constructing a whole
 * {@see WarrantRuleNode} in PHP instead of the string DSL. {@see Build::rule()}
 * makes one, and so does `$rule()` inside {@see Build::ruleSet()}.
 *
 * It extends {@see WarrantConditionBuilder} with the can/cannot clause half of a rule
 * (`theyCan` / `theyCannot` / `theyCannotBecause`) and finalization via
 * `toRule()`. The condition methods it inherits return `static`, so a top-level
 * chain keeps the rule builder — `->if(...)->theyCan(...)` works — while a group
 * closure only ever receives a bare condition builder.
 *
 * ```php
 * Build::rule()
 *     ->if('is_self')
 *     ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
 *     ->theyCan('view', 'update')
 *     ->theyCannotBecause('delete', 'This record is locked.');
 * ```
 *
 * Everything that accepts rule text accepts the builder too, and resolves it
 * with `toRule()` itself.
 */
final class WarrantRuleBuilder extends WarrantConditionBuilder
{
    /** @var list<list<string>> */
    private array $canClauses = [];

    /** @var list<array{abilities: list<string>, message: string|Closure|null}> */
    private array $cannotClauses = [];

    // -- can/cannot clauses ---------------------------------------------------

    /**
     * Grant $abilities. With none the can clause is generic, as a rule template's
     * body writes it: the `@include` that expands the template names the
     * abilities.
     */
    public function theyCan(string ...$abilities): static
    {
        $this->canClauses[] = array_values($abilities);

        return $this;
    }

    /**
     * Deny $abilities. With none the cannot clause is generic, as {@see theyCan()}'s is.
     */
    public function theyCannot(string ...$abilities): static
    {
        $this->cannotClauses[] = ['abilities' => array_values($abilities), 'message' => null];

        return $this;
    }

    /**
     * Deny the given abilities with a denial message, surfaced when this cannot clause is
     * the attributable cause of a singular-target denial. Each call adds one
     * cannot clause, so calling it more than once gives different abilities different
     * messages. Pass a single ability as a string or several as an array; the
     * abilities in one call share the message.
     *
     * With no abilities the cannot clause is generic, as {@see theyCan()}'s is, and the
     * message is passed by name: `theyCannotBecause(message: '...')`. The message
     * is required all the same, so a lone ability passed by position, its message
     * forgotten, is reported rather than read as a message.
     *
     * @param string|list<string> $abilities
     * @param string|Closure(WarrantDenialContext):(string|\Throwable)|null $message
     */
    public function theyCannotBecause(string|array $abilities = [], string|Closure|null $message = null): static
    {
        if ($message === null) {
            throw new LogicException(
                "theyCannotBecause() needs a message; pass it after the abilities, or by name for a cannot clause with no abilities: theyCannotBecause(message: '...')."
            );
        }

        $this->cannotClauses[] = [
            'abilities' => is_array($abilities) ? array_values($abilities) : [$abilities],
            'message' => $message,
        ];

        return $this;
    }

    // -- materialization ------------------------------------------------------

    /**
     * The rule, every node placed in $source: the source of the syntax that
     * holds it, or a new one when the rule is the root of what is handed over.
     */
    public function toRule(SyntaxSource $source = new SyntaxSource(null)): WarrantRuleNode
    {
        if ($this->canClauses === [] && $this->cannotClauses === []) {
            throw new LogicException(
                "A rule needs at least one 'they can ...' or 'they cannot ...' clause; call theyCan(), theyCannot(), or theyCannotBecause() before toRule()."
            );
        }

        return new WarrantRuleNode(
            $this->buildConditions($source),
            array_map(fn (array $abilities): CanClauseNode => new CanClauseNode(
                $this->abilityNodes($abilities, $source),
                null,
                self::location($source),
            ), $this->canClauses),
            array_map(fn (array $clause): CannotClauseNode => new CannotClauseNode(
                $this->abilityNodes($clause['abilities'], $source),
                $clause['message'] === null ? null : self::argumentNode($clause['message'], $source),
                self::location($source),
            ), $this->cannotClauses),
            self::location($source),
        );
    }

    /**
     * @param list<string> $abilities
     * @return list<NameNode>
     */
    private function abilityNodes(array $abilities, SyntaxSource $source): array
    {
        return array_map(fn (string $ability) => self::nameNode($ability, $source), $abilities);
    }
}
