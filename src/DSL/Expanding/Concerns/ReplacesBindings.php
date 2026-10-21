<?php

namespace Warrant\DSL\Expanding\Concerns;

use InvalidArgumentException;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Validation\AbilityMistakes;

/**
 * How {@see Expander} replaces every binding with its value, so an
 * expanded syntax holds none.
 *
 * @internal
 */
trait ReplacesBindings
{
    /**
     * $node with every binding in it replaced by its value: a value that is
     * itself a node by that node, and any other by a literal at the binding's
     * location. A binding standing for abilities is replaced by a name for
     * each ability its value names, at the binding's location. A node with no
     * binding in it comes back as the
     * same instance, so a reference keeps its identity.
     *
     * @template T of INode
     * @param T $node
     * @return T
     */
    private static function withBindingsReplaced(INode $node): INode
    {
        if ($node instanceof BindingNode) {
            $value = $node->value();

            return $value instanceof INode ? $value : new LiteralNode($value, $node->location);
        }

        $rebuilt = match (true) {
            $node instanceof AndNode, $node instanceof OrNode => new ($node::class)(
                self::withBindingsReplaced($node->leftSide),
                self::withBindingsReplaced($node->rightSide),
                $node->location,
            ),
            $node instanceof NotNode => new NotNode(self::withBindingsReplaced($node->operand), $node->location),
            $node instanceof ConditionNode => new ConditionNode($node->conditionKey, self::eachWithBindingsReplaced($node->parameters), $node->location),
            $node instanceof CrossSchemaCanNode => new CrossSchemaCanNode(
                $node->schemaKey,
                self::abilityWithBindingReplaced($node->ability),
                $node->isRowBound,
                self::eachWithBindingsReplaced($node->boundKey),
                self::eachWithBindingsReplaced($node->contextMap),
                $node->alias,
                $node->location,
            ),
            $node instanceof CrossSchemaConditionNode => new CrossSchemaConditionNode(
                $node->schemaKey,
                self::withBindingsReplaced($node->predicate),
                $node->isRowBound,
                self::eachWithBindingsReplaced($node->boundKey),
                self::eachWithBindingsReplaced($node->contextMap),
                $node->alias,
                $node->location,
            ),
            $node instanceof WithEntryNode => new WithEntryNode($node->key, self::withBindingsReplaced($node->value), $node->location),
            $node instanceof SqlRef => new SqlRef(self::withBindingsReplaced($node->sql), $node->location),
            $node instanceof CanClauseNode => new CanClauseNode(
                self::abilitiesWithBindingsReplaced($node->abilities),
                $node->message === null ? null : self::withBindingsReplaced($node->message),
                $node->location,
            ),
            $node instanceof CannotClauseNode => new CannotClauseNode(
                self::abilitiesWithBindingsReplaced($node->abilities),
                $node->message === null ? null : self::withBindingsReplaced($node->message),
                $node->location,
            ),
            $node instanceof WarrantRuleNode => new WarrantRuleNode(
                $node->conditions === null ? null : self::withBindingsReplaced($node->conditions),
                self::eachWithBindingsReplaced($node->canClauses),
                self::eachWithBindingsReplaced($node->cannotClauses),
                $node->location,
            ),
            $node instanceof IncludeInvocationNode => new IncludeInvocationNode(
                $node->templateKey,
                self::eachWithBindingsReplaced($node->arguments),
                self::abilitiesWithBindingsReplaced($node->abilities),
                $node->location,
            ),
            $node instanceof AbilityBlockNode => new AbilityBlockNode(self::abilitiesWithBindingsReplaced($node->abilities), self::eachWithBindingsReplaced($node->entries), $node->location),
            default => $node,
        };

        return $rebuilt->children() === $node->children() ? $node : $rebuilt;
    }

    /**
     * $nodes, each with every binding in it replaced by its value.
     *
     * @template T of INode
     * @param list<T> $nodes
     * @return list<T>
     */
    private static function eachWithBindingsReplaced(array $nodes): array
    {
        return array_map(self::withBindingsReplaced(...), $nodes);
    }

    /**
     * $abilities with each binding among them replaced by a name for every
     * ability its value names, in order, where the binding is written: one for
     * an ability name, and one for each name in a list.
     *
     * @param list<NameNode|BindingNode> $abilities
     * @return list<NameNode>
     */
    private static function abilitiesWithBindingsReplaced(array $abilities): array
    {
        $replaced = [];

        foreach ($abilities as $ability) {
            if (! $ability instanceof BindingNode) {
                $replaced[] = $ability;

                continue;
            }

            $mistake = AbilityMistakes::inBoundList($ability);

            if ($mistake !== null) {
                throw new InvalidArgumentException($mistake);
            }

            foreach (AbilityMistakes::boundNames($ability) as $name) {
                $replaced[] = new NameNode($name, $ability->location);
            }
        }

        return $replaced;
    }

    /**
     * $ability, or the name a binding's value gives it, where the binding is
     * written.
     */
    private static function abilityWithBindingReplaced(NameNode|BindingNode $ability): NameNode
    {
        if (! $ability instanceof BindingNode) {
            return $ability;
        }

        $mistake = AbilityMistakes::inBoundAbility($ability);

        if ($mistake !== null) {
            throw new InvalidArgumentException($mistake);
        }

        return new NameNode($ability->value(), $ability->location);
    }
}
