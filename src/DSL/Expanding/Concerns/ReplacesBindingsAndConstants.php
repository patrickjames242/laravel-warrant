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
 * Where abilities are written, a bare name is an ability of the schema they
 * belong to when that schema declares one, and a constant naming abilities
 * otherwise. The abilities of a `can(... for <handle>)` belong to the schema the
 * handle reaches, so a constant there is looked up from that schema.
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
     *   a `check(...)` predicate, the schema the check names: whose abilities a
     *   bare name in an ability list names.
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
                $this->canAbilityWithBindingOrConstantReplaced($node, $schemaKey, $writtenIn),
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
                $this->abilitiesWithBindingsAndConstantsReplaced($node->abilities, $schemaKey, $writtenIn),
                $node->message === null ? null : $replaced($node->message),
                $node->location,
            ),
            $node instanceof CannotClauseNode => new CannotClauseNode(
                $this->abilitiesWithBindingsAndConstantsReplaced($node->abilities, $schemaKey, $writtenIn),
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
                $this->abilitiesWithBindingsAndConstantsReplaced($node->abilities, $schemaKey, $writtenIn),
                $node->location,
            ),
            $node instanceof AbilityBlockNode => new AbilityBlockNode($this->abilitiesWithBindingsAndConstantsReplaced($node->abilities, $schemaKey, $writtenIn), $eachReplaced($node->entries), $node->location),
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
     * The ability a `can(...)` asks about, with a binding or constant replaced.
     * With no `for` it is an ability of the schema the expression is about, and
     * a constant naming it is looked up as the expression's are. With a `for` it
     * is an ability of the schema the handle reaches, and a constant naming it is
     * looked up from that schema.
     */
    private function canAbilityWithBindingOrConstantReplaced(CrossSchemaCanNode $can, string $schemaKey, ?ISchemaId $writtenIn): NameNode
    {
        if ($can->handle === null) {
            return $this->abilityWithBindingOrConstantReplaced($can->ability, $schemaKey, $writtenIn);
        }

        $targetKey = $this->handleTargetKey($can->handle, $schemaKey);

        return $this->abilityWithBindingOrConstantReplaced(
            $can->ability,
            $targetKey,
            $targetKey === null ? null : new KeyedSchemaId($targetKey),
        );
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
        $targetKey = $this->handleTargetKey($check->handle, $schemaKey);

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
     * $abilities with each binding or constant among them replaced by a name
     * for every ability its value names, in order, where it is written: one for
     * an ability name, and one for each name in a list.
     *
     * A bare name is a constant too when it is not one of the abilities of
     * $abilitiesOf, and is replaced by what its value names when a constant of
     * that name is found. One nothing declares is kept, for validation and the
     * compiler to report as the ability it is not.
     *
     * @param list<NameNode|BindingNode|ConstantRef> $abilities
     * @param string $abilitiesOf The schema whose abilities the list names.
     * @param ISchemaId|null $writtenIn Where a bare constant is looked up from,
     *   or null to look each up from the text it was written in.
     * @return list<NameNode>
     */
    private function abilitiesWithBindingsAndConstantsReplaced(array $abilities, string $abilitiesOf, ?ISchemaId $writtenIn): array
    {
        $replaced = [];

        foreach ($abilities as $ability) {
            [$written, $value] = $this->abilityValue($ability, $abilitiesOf, $writtenIn);

            if ($written === null) {
                $replaced[] = $ability;

                continue;
            }

            $mistake = AbilityMistakes::inListValue($written, $value);

            if ($mistake !== null) {
                throw new InvalidArgumentException($mistake);
            }

            foreach (AbilityMistakes::namesIn($value) as $name) {
                $replaced[] = new NameNode($name, $ability->location);
            }
        }

        return $replaced;
    }

    /**
     * $ability, or the name the value of a binding or constant gives it, where it
     * is written. A bare name is looked up as a constant, as in a list, when
     * $abilitiesOf is known and does not declare it.
     *
     * @param string|null $abilitiesOf The schema whose ability it is; null when
     *   that schema cannot be found, and a bare name is kept.
     */
    private function abilityWithBindingOrConstantReplaced(
        NameNode|BindingNode|ConstantRef $ability,
        ?string $abilitiesOf,
        ?ISchemaId $writtenIn,
    ): NameNode {
        [$written, $value] = $ability instanceof NameNode && $abilitiesOf === null
            ? [null, null]
            : $this->abilityValue($ability, (string) $abilitiesOf, $writtenIn);

        if ($written === null) {
            return $ability;
        }

        $mistake = AbilityMistakes::inOneAbilityValue($written, $value);

        if ($mistake !== null) {
            throw new InvalidArgumentException($mistake);
        }

        return new NameNode($value, $ability->location);
    }

    /**
     * The binding or constant $ability is, with its value; or [null, null] when
     * it is a name that stays as written: `*`, an ability of $abilitiesOf, or a
     * name no constant has.
     *
     * @return array{0: BindingNode|ConstantRef|null, 1: mixed}
     */
    private function abilityValue(NameNode|BindingNode|ConstantRef $ability, string $abilitiesOf, ?ISchemaId $writtenIn): array
    {
        if ($ability instanceof BindingNode) {
            return [$ability, $ability->value()];
        }

        if ($ability instanceof ConstantRef) {
            return [$ability, $this->constantValue($ability, $abilitiesOf, $writtenIn)];
        }

        if ($ability->name === '*' || $ability->name === INode::MISSING_NAME) {
            return [null, null];
        }

        $vocabulary = $this->catalog->find($abilitiesOf);

        if ($vocabulary === null) {
            return [null, null];
        }

        $lookup = $this->catalog->constructs();
        $found = $lookup->constantNamingAbilities(
            $ability->name,
            $vocabulary,
            $writtenIn ?? $lookup->writtenIn($ability->location->source->producer(), $abilitiesOf),
        );

        if ($found === null || $found->definition->value === $ability->name) {
            return [null, null];
        }

        return [new ConstantRef(null, $ability, $ability->location), $found->definition->value];
    }
}
