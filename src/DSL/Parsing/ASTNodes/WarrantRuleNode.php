<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Closure;
use InvalidArgumentException;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\Schema\WarrantDenialContext;

/**
 * One rule: a condition, and the abilities it grants and denies when that
 * condition holds. The schema comes from whatever holds the rule — a
 * {@see RuleSetNode}, or the caller of a headless parse.
 *
 * Inside an ability block or a rule template's body the rule is headless: every
 * clause names no abilities, because the block header or the `@include` names
 * them. {@see withAbilities()} gives a headless rule the abilities it takes.
 */
readonly class WarrantRuleNode implements IRuleEntryNode
{
    /**
     * @param list<CanClauseNode> $canClauses The granted abilities, one clause
     *   per `they can` written. Flatten with {@see canAbilities()}.
     * @param list<CannotClauseNode> $cannotClauses The denied abilities, grouped into
     *   clauses so each group can carry its own denial message. Flatten with
     *   {@see cannotAbilities()}; resolve an ability's message with
     *   {@see messageFor()}. A denial message is only ever surfaced for a matching
     *   `cannot`, so it lives on a clause — a rule that only grants has no place
     *   for one.
     */
    public function __construct(
        public ?IBooleanExpressionNode $conditions,
        public array $canClauses,
        public array $cannotClauses,
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
     * Start a fluent, query-builder-style rule construction.
     */
    public static function build(): WarrantRuleBuilder
    {
        return new WarrantRuleBuilder;
    }

    /**
     * Every granted ability, flattened across all clauses in order.
     *
     * @return list<string>
     */
    public function canAbilities(): array
    {
        $abilities = [];

        foreach ($this->canClauses as $clause) {
            foreach ($clause->abilities as $ability) {
                $abilities[] = $ability;
            }
        }

        return $abilities;
    }

    /**
     * Whether every clause is headless, naming no abilities of its own.
     */
    public function isHeadless(): bool
    {
        foreach ([...$this->canClauses, ...$this->cannotClauses] as $clause) {
            if ($clause->abilities !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any clause is headless. Outside an ability block or a template body
     * such a clause grants or denies nothing, because nothing names what it is
     * about.
     */
    public function hasHeadlessClause(): bool
    {
        foreach ([...$this->canClauses, ...$this->cannotClauses] as $clause) {
            if ($clause->abilities === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * A copy of this headless rule with $abilities on every clause: the rule its
     * clauses mean once the enclosing block header or `@include` has named what
     * they are about. Denial messages stay on the clauses that carried them.
     *
     * @param list<string> $abilities
     */
    public function withAbilities(array $abilities): self
    {
        if (! $this->isHeadless()) {
            throw new InvalidArgumentException(
                'Only a headless rule takes abilities from outside; this rule names its own.'
            );
        }

        return new self(
            $this->conditions,
            array_map(static fn (CanClauseNode $clause): CanClauseNode => new CanClauseNode($abilities), $this->canClauses),
            array_map(
                static fn (CannotClauseNode $clause): CannotClauseNode => new CannotClauseNode($abilities, $clause->message),
                $this->cannotClauses,
            ),
        );
    }

    /**
     * Every denied ability, flattened across all clauses in order. Used by the
     * compiler / reachability / validator as a plain membership list; messages
     * are irrelevant there.
     *
     * @return list<string>
     */
    public function cannotAbilities(): array
    {
        $abilities = [];

        foreach ($this->cannotClauses as $clause) {
            foreach ($clause->abilities as $ability) {
                $abilities[] = $ability;
            }
        }

        return $abilities;
    }

    /**
     * Whether this rule denies $ability — it appears in some clause, or a clause
     * denies `*`.
     */
    public function deniesAbility(string $ability): bool
    {
        foreach ($this->cannotClauses as $clause) {
            if (in_array($ability, $clause->abilities, true) || in_array('*', $clause->abilities, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The denial message for $ability: the first clause that lists it wins; else
     * the first clause that denies `*`; else null (no message). An exact listing
     * always beats a `*` clause.
     *
     * @return string|Closure(WarrantDenialContext):(string|\Throwable)|null
     */
    public function messageFor(string $ability): string|Closure|null
    {
        $wildcard = null;
        $wildcardFound = false;

        foreach ($this->cannotClauses as $clause) {
            if (in_array($ability, $clause->abilities, true)) {
                return $clause->message;
            }

            if (! $wildcardFound && in_array('*', $clause->abilities, true)) {
                $wildcard = $clause->message;
                $wildcardFound = true;
            }
        }

        return $wildcard;
    }

    public function hasCannot(): bool
    {
        return $this->cannotClauses !== [];
    }
}
