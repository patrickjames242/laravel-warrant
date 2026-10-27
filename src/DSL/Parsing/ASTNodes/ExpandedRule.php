<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Closure;
use LogicException;
use Warrant\Schema\Denials\WarrantDenialContext;

/**
 * One rule of an {@see ExpandedSyntax}: a {@see RuleNode} whose every
 * can/cannot clause names its abilities one by one, so which abilities it
 * grants and denies, and with what message, can be read off it.
 *
 * Only {@see ExpandedSyntax::expand()} makes one, from what expansion answered
 * with, so the abilities it reads are never a binding or a `*`.
 *
 * @internal
 */
final readonly class ExpandedRule
{
    public function __construct(
        public RuleNode $node,
    ) {}

    /**
     * Every granted ability, across all can clauses in order.
     *
     * @return list<string>
     */
    public function grantedAbilities(): array
    {
        return self::abilityNames($this->node->canClauses);
    }

    /**
     * Every denied ability, across all cannot clauses in order.
     *
     * @return list<string>
     */
    public function deniedAbilities(): array
    {
        return self::abilityNames($this->node->cannotClauses);
    }

    /**
     * Whether this rule denies $ability: it appears in some cannot clause.
     */
    public function denies(string $ability): bool
    {
        return in_array($ability, $this->deniedAbilities(), true);
    }

    /**
     * The denial message for $ability: the first cannot clause that lists it
     * wins; else null (no message). Expansion has put every binding's value in
     * its place, so the message is a literal's value.
     *
     * @return string|Closure(WarrantDenialContext):(string|\Throwable)|null
     */
    public function messageFor(string $ability): string|Closure|null
    {
        foreach ($this->node->cannotClauses as $clause) {
            if (in_array($ability, self::abilityNames([$clause]), true)) {
                return match (true) {
                    $clause->message === null => null,
                    $clause->message instanceof LiteralNode => $clause->message->value,
                    default => throw new LogicException(sprintf(
                        'An expanded rule\'s denial message is a literal, got %s.',
                        get_debug_type($clause->message),
                    )),
                };
            }
        }

        return null;
    }

    /**
     * The abilities $clauses name, in order.
     *
     * @param list<CanClauseNode|CannotClauseNode> $clauses
     * @return list<string>
     */
    private static function abilityNames(array $clauses): array
    {
        $names = [];

        foreach ($clauses as $clause) {
            foreach ($clause->abilities as $ability) {
                if (! $ability instanceof NameNode || $ability->name === '*') {
                    throw new LogicException(sprintf(
                        'An expanded rule names every ability on its own, got %s.',
                        $ability instanceof BindingNode ? "the binding [:{$ability->name}]" : '`*`',
                    ));
                }

                $names[] = $ability->name;
            }
        }

        return $names;
    }
}
