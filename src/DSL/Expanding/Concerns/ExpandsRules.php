<?php

namespace Warrant\DSL\Expanding\Concerns;

use Closure;
use InvalidArgumentException;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * How {@see Expander} expands a rule: its denial messages are checked, every
 * `*` replaced by the abilities the schema declares, and the derived conditions
 * in its condition expanded.
 *
 * @internal
 */
trait ExpandsRules
{
    /**
     * $rule with every `*` replaced and the derived conditions in its condition
     * expanded. A rule with nothing to expand comes back as the same instance.
     */
    private function expandRule(
        RuleNode $rule,
        ISchema $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): RuleNode {
        /* A message after `they can` could never surface, and one that is not
           text cannot be shown. The validator reports these against the text;
           this rejects them in any rule, built in code or not. */
        foreach ($rule->canClauses as $clause) {
            if ($clause->message !== null) {
                throw new InvalidArgumentException(LanguageRuleMessages::messageOnCanClause());
            }
        }

        foreach ($rule->cannotClauses as $clause) {
            $message = $clause->message;
            $isText = $message instanceof LiteralNode && (is_string($message->value) || $message->value instanceof Closure);

            if ($message !== null && ! $isText) {
                throw new InvalidArgumentException(LanguageRuleMessages::messageIsNotText());
            }
        }

        $canClauses = $this->clausesWithStarExpanded($rule->canClauses, $schemaKey);
        $cannotClauses = $this->clausesWithStarExpanded($rule->cannotClauses, $schemaKey);
        $conditions = $rule->conditions;

        if ($conditions !== null) {
            $writtenIn = $this->catalog->constructs()->writtenIn($conditions->location->source->producer(), $schemaKey);
            $conditions = $this->expandExpression($conditions, $schema, $schemaKey, $writtenIn, $trail);
        }

        return $conditions === $rule->conditions && $canClauses === $rule->canClauses && $cannotClauses === $rule->cannotClauses
            ? $rule
            : new RuleNode($conditions, $canClauses, $cannotClauses, $rule->location);
    }

    /**
     * $clauses with every `*` replaced by the abilities the schema declares. A
     * clause with no `*` comes back as the same instance.
     *
     * @template T of CanClauseNode|CannotClauseNode
     * @param list<T> $clauses
     * @return list<T>
     */
    private function clausesWithStarExpanded(array $clauses, string $schemaKey): array
    {
        return array_map(
            function (CanClauseNode|CannotClauseNode $clause) use ($schemaKey): CanClauseNode|CannotClauseNode {
                $abilities = $this->abilitiesWithStarExpanded($clause->abilities, $schemaKey);

                return $abilities === $clause->abilities ? $clause : new ($clause::class)($abilities, $clause->message, $clause->location);
            },
            $clauses,
        );
    }
}
