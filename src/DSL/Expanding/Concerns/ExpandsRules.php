<?php

namespace Warrant\DSL\Expanding\Concerns;

use Closure;
use InvalidArgumentException;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * How {@see Expander} expands a rule: its denial messages are checked, and the
 * derived conditions in its condition expanded.
 *
 * @internal
 */
trait ExpandsRules
{
    /**
     * $rule with the derived conditions in its condition expanded. A rule with
     * nothing to expand comes back as the same instance.
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

        if ($rule->conditions === null) {
            return $rule;
        }

        $writtenIn = $this->catalog->constructs()->writtenIn($rule->conditions->location->source->producer(), $schemaKey);
        $conditions = $this->expandExpression($rule->conditions, $schema, $schemaKey, $writtenIn, $trail);

        return $conditions === $rule->conditions
            ? $rule
            : new RuleNode($conditions, $rule->canClauses, $rule->cannotClauses, $rule->location);
    }
}
