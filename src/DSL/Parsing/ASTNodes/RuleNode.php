<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * One rule: a condition, and the abilities it grants and denies when that
 * condition holds. The schema comes from whatever holds the rule — a
 * {@see RuleSetNode}, or the caller of an unscoped parse.
 *
 * Inside an ability block or a rule template's body the rule is generic: every
 * can/cannot clause names no abilities, because the block header or the `@include` names
 * them. {@see withAbilities()} gives a generic rule the abilities it takes.
 */
readonly class RuleNode implements IRuleEntryNode
{
    /**
     * @param list<CanClauseNode> $canClauses The granted abilities, one can clause
     *   per `they can` written.
     * @param list<CannotClauseNode> $cannotClauses The denied abilities, grouped into
     *   cannot clauses so each group can carry its own denial message. A denial
     *   message is only ever surfaced for a matching `cannot`, so it lives on a
     *   cannot clause — a rule that only grants has no place for one. Which
     *   abilities a rule grants and denies, and with what message, is read off
     *   it once it is expanded, by {@see ExpandedRule}.
     */
    public function __construct(
        public ?IBooleanExpressionNode $conditions,
        public array $canClauses,
        public array $cannotClauses,
        public SyntaxLocation $location,
    ) {
        foreach ($canClauses as $clause) {
            if (! $clause instanceof CanClauseNode) {
                throw new InvalidArgumentException(sprintf(
                    'A rule\'s can clauses are CanClauseNode instances, got %s.',
                    get_debug_type($clause),
                ));
            }
        }

        foreach ($cannotClauses as $clause) {
            if (! $clause instanceof CannotClauseNode) {
                throw new InvalidArgumentException(sprintf(
                    'A rule\'s cannot clauses are CannotClauseNode instances, got %s.',
                    get_debug_type($clause),
                ));
            }
        }
    }

    /**
     * Whether every can/cannot clause is generic, naming no abilities of its own.
     */
    public function isGeneric(): bool
    {
        foreach ([...$this->canClauses, ...$this->cannotClauses] as $clause) {
            if ($clause->abilities !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any can/cannot clause is generic. Outside an ability block or a template body
     * such a can/cannot clause grants or denies nothing, because nothing names what it is
     * about.
     */
    public function hasGenericCanOrCannotClause(): bool
    {
        foreach ([...$this->canClauses, ...$this->cannotClauses] as $clause) {
            if ($clause->abilities === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * A copy of this generic rule with $abilities on every can/cannot clause: the rule its
     * clauses mean once the enclosing block header or `@include` has named what
     * they are about. Denial messages stay on the cannot clauses that carried them.
     *
     * @param list<NameNode|EveryAbilityExceptNode> $abilities
     */
    public function withAbilities(array $abilities): self
    {
        if (! $this->isGeneric()) {
            throw new InvalidArgumentException(
                'Only a generic rule takes abilities from outside; this rule names its own.'
            );
        }

        return new self(
            $this->conditions,
            array_map(static fn (CanClauseNode $clause): CanClauseNode => new CanClauseNode($abilities, $clause->message, $clause->location), $this->canClauses),
            array_map(
                static fn (CannotClauseNode $clause): CannotClauseNode => new CannotClauseNode($abilities, $clause->message, $clause->location),
                $this->cannotClauses,
            ),
            $this->location,
        );
    }

    public function hasCannot(): bool
    {
        return $this->cannotClauses !== [];
    }

    public function children(): array
    {
        return [
            ...($this->conditions === null ? [] : [$this->conditions]),
            ...$this->canClauses,
            ...$this->cannotClauses,
        ];
    }
}
