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
use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaHandleNode;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\DSL\Validation\AbilityMistakes;
use Warrant\DSL\Validation\LanguageRuleMessages;

/**
 * How {@see Expander} replaces every binding and every constant with its value,
 * so an expanded syntax holds neither.
 *
 * A constant is looked up from where it was written. A bare name is looked up
 * from the schema its text was written in: the global schema that answered with
 * it, or else the schema the rules are for. Inside a `check(...)` predicate it
 * is looked up from the schema the check names, as the predicate's conditions
 * are. A prefixed name is looked up in the schema the prefix names alone.
 *
 * @internal
 */
trait ReplacesBindingsAndConstants
{
    /**
     * $node with every binding and constant in it replaced by its value: a
     * binding's value that is itself a node by that node, and any other value
     * by a literal at the binding's or constant's location. A binding standing
     * for abilities is replaced by a name for each ability its value names, at
     * the binding's location. A node with nothing to replace in it comes back as
     * the same instance, so a reference keeps its identity.
     *
     * @template T of INode
     * @param T $node
     * @param string $schemaKey The schema the rules $node is in are for, or, in
     *   a `check(...)` predicate, the schema the check names.
     * @param ISchemaId|null $writtenIn Where a bare constant in $node is looked
     *   up from, when every one is looked up from the same place, as in a
     *   predicate. Null to look each one up from the text it was written in.
     * @return T
     */
    private function withBindingsAndConstantsReplaced(INode $node, string $schemaKey, ?ISchemaId $writtenIn = null): INode
    {
        if ($node instanceof BindingNode) {
            $value = $node->value();

            return $value instanceof INode ? $value : new LiteralNode($value, $node->location);
        }

        if ($node instanceof ConstantRef) {
            return new LiteralNode($this->constantValue($node, $schemaKey, $writtenIn), $node->location);
        }

        $replaced = fn (INode $child): INode => $this->withBindingsAndConstantsReplaced($child, $schemaKey, $writtenIn);
        $eachReplaced = fn (array $children): array => $this->eachWithBindingsAndConstantsReplaced($children, $schemaKey, $writtenIn);

        $rebuilt = match (true) {
            $node instanceof AndNode, $node instanceof OrNode => new ($node::class)(
                $replaced($node->leftSide),
                $replaced($node->rightSide),
                $node->location,
            ),
            $node instanceof NotNode => new NotNode($replaced($node->operand), $node->location),
            $node instanceof ConditionNode => new ConditionNode($node->conditionKey, $eachReplaced($node->parameters), $node->location),
            $node instanceof CrossSchemaCanNode => new CrossSchemaCanNode(
                $node->handle === null ? null : $replaced($node->handle),
                self::abilityWithBindingReplaced($node->ability),
                $eachReplaced($node->contextMap),
                $node->alias,
                $node->location,
            ),
            $node instanceof CrossSchemaConditionNode => new CrossSchemaConditionNode(
                $replaced($node->handle),
                $this->predicateWithBindingsAndConstantsReplaced($node, $schemaKey),
                $eachReplaced($node->contextMap),
                $node->alias,
                $node->location,
            ),
            $node instanceof SchemaHandleNode => new SchemaHandleNode(
                $node->schemaKey,
                $node->hasRowSelector,
                $eachReplaced($node->boundKey),
                $node->writtenQuantifier,
                $node->location,
            ),
            $node instanceof WithEntryNode => new WithEntryNode($node->key, $replaced($node->value), $node->location),
            $node instanceof SqlRef => new SqlRef($this->sqlWithBindingOrConstantReplaced($node->sql, $schemaKey, $writtenIn), $node->location),
            $node instanceof CanClauseNode => new CanClauseNode(
                self::abilitiesWithBindingsReplaced($node->abilities),
                $node->message === null ? null : $replaced($node->message),
                $node->location,
            ),
            $node instanceof CannotClauseNode => new CannotClauseNode(
                self::abilitiesWithBindingsReplaced($node->abilities),
                $node->message === null ? null : $replaced($node->message),
                $node->location,
            ),
            $node instanceof RuleNode => new RuleNode(
                $node->conditions === null ? null : $replaced($node->conditions),
                $eachReplaced($node->canClauses),
                $eachReplaced($node->cannotClauses),
                $node->location,
            ),
            $node instanceof IncludeInvocationNode => new IncludeInvocationNode(
                $node->templateKey,
                $eachReplaced($node->arguments),
                self::abilitiesWithBindingsReplaced($node->abilities),
                $node->location,
            ),
            $node instanceof AbilityBlockNode => new AbilityBlockNode(self::abilitiesWithBindingsReplaced($node->abilities), $eachReplaced($node->entries), $node->location),
            $node instanceof WhenBlockNode => new WhenBlockNode(
                $replaced($node->condition),
                $eachReplaced($node->entries),
                $node->otherwiseEntries === null ? null : $eachReplaced($node->otherwiseEntries),
                $node->location,
            ),
            default => $node,
        };

        return $rebuilt->children() === $node->children() ? $node : $rebuilt;
    }

    /**
     * The predicate of $check with every binding and constant in it replaced.
     * Its constants are looked up from the schema the check names, whose
     * conditions those are. A check naming a schema that cannot be found has
     * them looked up as the rule's own are; the validator and the compiler
     * reject the check itself.
     *
     * @param string $schemaKey The schema the rule is for, whose row relation an
     *   `its` handle follows.
     */
    private function predicateWithBindingsAndConstantsReplaced(CrossSchemaConditionNode $check, string $schemaKey): INode
    {
        $targetKey = $this->checkTargetKey($check, $schemaKey);

        return $targetKey === null
            ? $this->withBindingsAndConstantsReplaced($check->predicate, $schemaKey)
            : $this->withBindingsAndConstantsReplaced($check->predicate, $targetKey, new KeyedSchemaId($targetKey));
    }

    /**
     * The body of an `@sql` reference with a binding or constant in it replaced
     * by its value. A constant standing for anything but a string has no SQL to
     * give.
     */
    private function sqlWithBindingOrConstantReplaced(INode $sql, string $schemaKey, ?ISchemaId $writtenIn): INode
    {
        $replaced = $this->withBindingsAndConstantsReplaced($sql, $schemaKey, $writtenIn);

        if ($sql instanceof ConstantRef && $replaced instanceof LiteralNode && ! is_string($replaced->value)) {
            throw new InvalidArgumentException(LanguageRuleMessages::sqlConstantNotText($sql->written(), $replaced->value));
        }

        return $replaced;
    }

    /**
     * The value of the constant $ref names: for a prefixed name, the named
     * schema's own; for a bare one, the first found from $writtenIn, or from
     * the text $ref was written in.
     */
    private function constantValue(ConstantRef $ref, string $schemaKey, ?ISchemaId $writtenIn): mixed
    {
        $lookup = $this->catalog->constructs();

        if ($ref->schema !== null) {
            $found = $lookup->constantDeclaredBy($ref->schema->name, $ref->name->name);

            if ($found === null) {
                throw new InvalidArgumentException($this->catalog->find($ref->schema->name) === null
                    ? LanguageRuleMessages::unknownConstantSchema($ref->schema->name, $ref->written())
                    : LanguageRuleMessages::constantNotDeclaredBy($ref->schema->name, $ref->name->name));
            }

            return $found->definition->value;
        }

        $writtenIn ??= $lookup->writtenIn($ref->location->source->producer(), $schemaKey);

        return ($lookup->constant($ref->name->name, $writtenIn)
            ?? throw new InvalidArgumentException(LanguageRuleMessages::unknownConstant($writtenIn, $ref->name->name)))
            ->definition->value;
    }

    /**
     * $nodes, each with every binding and constant in it replaced by its value.
     *
     * @template T of INode
     * @param list<T> $nodes
     * @return list<T>
     */
    private function eachWithBindingsAndConstantsReplaced(array $nodes, string $schemaKey, ?ISchemaId $writtenIn = null): array
    {
        return array_map(fn (INode $node): INode => $this->withBindingsAndConstantsReplaced($node, $schemaKey, $writtenIn), $nodes);
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
