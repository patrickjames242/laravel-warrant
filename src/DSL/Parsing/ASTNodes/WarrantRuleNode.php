<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Closure;
use InvalidArgumentException;
use LogicException;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\Schema\WarrantDenialContext;

/**
 * One rule: a condition, and the abilities it grants and denies when that
 * condition holds. The schema comes from whatever holds the rule — a
 * {@see RuleSetNode}, or the caller of an unscoped parse.
 *
 * Inside an ability block or a rule template's body the rule is generic: every
 * clause names no abilities, because the block header or the `@include` names
 * them. {@see withAbilities()} gives a generic rule the abilities it takes.
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
     * Every granted ability, flattened across all clauses in order.
     *
     * @return list<string>
     */
    public function canAbilities(): array
    {
        $abilities = [];

        foreach ($this->canClauses as $clause) {
            foreach ($clause->abilities as $ability) {
                $abilities[] = self::nameOf($ability);
            }
        }

        return $abilities;
    }

    /**
     * Whether every clause is generic, naming no abilities of its own.
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
     * Whether any clause is generic. Outside an ability block or a template body
     * such a clause grants or denies nothing, because nothing names what it is
     * about.
     */
    public function hasGenericClause(): bool
    {
        foreach ([...$this->canClauses, ...$this->cannotClauses] as $clause) {
            if ($clause->abilities === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * A copy of this generic rule with $abilities on every clause: the rule its
     * clauses mean once the enclosing block header or `@include` has named what
     * they are about. Denial messages stay on the clauses that carried them.
     *
     * @param list<NameNode> $abilities
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
                $abilities[] = self::nameOf($ability);
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
            $names = self::abilityNames($clause);

            if (in_array($ability, $names, true) || in_array('*', $names, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The denial message for $ability: the first clause that lists it wins; else
     * the first clause that denies `*`; else null (no message). An exact listing
     * always beats a `*` clause. The message is its value, as a literal holds it
     * or a binding's source gives it.
     *
     * @return string|Closure(WarrantDenialContext):(string|\Throwable)|null
     */
    public function messageFor(string $ability): string|Closure|null
    {
        $wildcard = null;
        $wildcardFound = false;

        foreach ($this->cannotClauses as $clause) {
            $names = self::abilityNames($clause);

            if (in_array($ability, $names, true)) {
                return self::messageValue($clause->message);
            }

            if (! $wildcardFound && in_array('*', $names, true)) {
                $wildcard = self::messageValue($clause->message);
                $wildcardFound = true;
            }
        }

        return $wildcard;
    }

    /**
     * @return list<string>
     */
    private static function abilityNames(CannotClauseNode $clause): array
    {
        return array_map(self::nameOf(...), $clause->abilities);
    }

    /**
     * The ability $ability names. A binding names none until expansion puts its
     * value in its place, so only an expanded rule's abilities can be read.
     */
    private static function nameOf(NameNode|BindingNode $ability): string
    {
        if ($ability instanceof BindingNode) {
            throw new LogicException(sprintf(
                'The ability binding [:%s] names no ability until the rule is expanded.',
                $ability->name,
            ));
        }

        return $ability->name;
    }

    /**
     * @return string|Closure(WarrantDenialContext):(string|\Throwable)|null
     */
    private static function messageValue(?INode $message): string|Closure|null
    {
        return match (true) {
            $message === null => null,
            $message instanceof BindingNode => $message->value(),
            $message instanceof LiteralNode => $message->value,
            default => throw new InvalidArgumentException(sprintf(
                'A denial message is a literal or a binding, got %s.',
                get_debug_type($message),
            )),
        };
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
