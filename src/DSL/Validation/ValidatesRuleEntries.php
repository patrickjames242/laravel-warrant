<?php

namespace Warrant\DSL\Validation;

use Closure;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Resolution\ISchemaId;
use Warrant\Schema\Vocabulary;

/**
 * The checks a {@see SyntaxValidator} makes of rule entries as written: that
 * they name their abilities, or leave them to an `@include`, as what produced
 * them says; that every ability they name and every template they include is
 * one the schema declares; and that each rule's own can/cannot clauses and messages are
 * well formed. A rule's `if` expression, and a when block's condition, is
 * checked by {@see ValidatesExpressions}.
 *
 * A when block is transparent to all of that: the entries in its bodies are
 * held to what the entries around it are, as the expansion will put them
 * where the block stands.
 *
 * The abilities are checked where they are written, because the expansion
 * only carries them on the rules they end up on, and an empty block or a
 * template with an empty body puts them on none. So is an include naming a
 * template the schema does not declare, or giving it too few arguments, which
 * the expansion rejects the same way before it asks the template for
 * anything.
 *
 * @internal
 */
trait ValidatesRuleEntries
{
    /**
     * Validate rule entries as written, with $mode saying whether they name
     * their abilities.
     *
     * @param list<IRuleEntryNode> $entries
     * @param Vocabulary|null $schema The schema the entries belong to;
     *   null when it is not known, and their names are not looked up.
     * @param string|null $schemaKey The key of the schema the entries are for:
     *   the name its own rows go by in a `@column` reference, and the schema an
     *   error about an entry that names no abilities names.
     */
    private function validateEntries(array $entries, GenericMode $mode, ?Vocabulary $schema, ?string $schemaKey): void
    {
        if ($mode === GenericMode::Either) {
            $this->assertNamingAgrees($entries);
        }

        $this->validateEachEntry($entries, $mode, $schema, $schemaKey);
    }

    /**
     * Validate each of $entries, and the entries in a when block's bodies, with
     * $mode saying whether they name their abilities.
     *
     * @param list<IRuleEntryNode> $entries
     */
    private function validateEachEntry(array $entries, GenericMode $mode, ?Vocabulary $schema, ?string $schemaKey): void
    {
        foreach ($entries as $entry) {
            if ($entry instanceof WhenBlockNode) {
                $this->validateWhenBlock($entry, $schema, $schemaKey);
                $this->validateEachEntry([...$entry->entries, ...$entry->otherwiseEntries ?? []], $mode, $schema, $schemaKey);
            } elseif ($entry instanceof AbilityBlockNode) {
                if ($mode === GenericMode::RequireGeneric) {
                    $this->fail(
                        DiagnosticCode::AbilitiesNamedWhereGeneric,
                        LanguageRuleMessages::namedWhereGeneric('An ability block'),
                        $entry->location,
                    );
                }

                $this->validateAbilityBlock($entry, $schema, $schemaKey);
            } elseif ($entry instanceof IncludeInvocationNode) {
                if ($mode === GenericMode::RequireNotGeneric && $entry->isGeneric()) {
                    $this->fail(
                        DiagnosticCode::AbilitiesUnnamedOutsideBlock,
                        LanguageRuleMessages::unnamedOutsideBlock($schemaKey),
                        $entry->location,
                    );
                }

                if ($mode === GenericMode::RequireGeneric && ! $entry->isGeneric()) {
                    $this->fail(
                        DiagnosticCode::AbilitiesNamedWhereGeneric,
                        LanguageRuleMessages::namedWhereGeneric('This @include'),
                        $entry->abilities[0]->location,
                    );
                }

                $this->assertAbilitiesDeclared($entry->abilities, $schema, $this->writtenIn($entry, $schema, $schemaKey));
                $this->assertAbilityListsWellWritten($entry);
                $this->assertTemplateDeclared($entry, $schema, $schemaKey);
            } elseif ($entry instanceof RuleNode) {
                foreach ([...$entry->canClauses, ...$entry->cannotClauses] as $clause) {
                    if ($mode === GenericMode::RequireNotGeneric && $clause->abilities === []) {
                        $this->fail(
                            DiagnosticCode::AbilitiesUnnamedOutsideBlock,
                            LanguageRuleMessages::unnamedOutsideBlock($schemaKey),
                            $clause->location,
                        );
                    }

                    if ($mode === GenericMode::RequireGeneric && $clause->abilities !== []) {
                        $this->fail(
                            DiagnosticCode::AbilitiesNamedWhereGeneric,
                            LanguageRuleMessages::namedWhereGeneric('This can/cannot clause'),
                            $clause->abilities[0]->location,
                        );
                    }
                }

                $this->validateRule($entry, $schema, $schemaKey);
            }
        }
    }

    /**
     * Rules with no `for` header either all name their abilities or all leave
     * them to be named where the rules are placed: the first can/cannot clause, include or
     * block decides, and each one after it that does not do the same is
     * reported. What a when block holds is held to that with everything else.
     *
     * @param list<IRuleEntryNode> $entries
     */
    private function assertNamingAgrees(array $entries): void
    {
        $first = null;

        foreach ($entries as $written) {
            foreach ($written instanceof WhenBlockNode ? $written->entriesInside() : [$written] as $entry) {
                $named = match (true) {
                    $entry instanceof AbilityBlockNode => [['An ability block', true, $entry]],
                    $entry instanceof IncludeInvocationNode => [['This @include', ! $entry->isGeneric(), $entry]],
                    $entry instanceof RuleNode => array_map(
                        static fn (CanClauseNode|CannotClauseNode $clause): array => ['This can/cannot clause', $clause->abilities !== [], $clause],
                        [...$entry->canClauses, ...$entry->cannotClauses],
                    ),
                    default => [],
                };

                foreach ($named as [$what, $names, $node]) {
                    $first ??= $names;

                    if ($names !== $first) {
                        $this->fail(DiagnosticCode::AbilityNamingDisagrees, LanguageRuleMessages::namingDisagrees($what, $names), $node->location);
                    }
                }
            }
        }
    }

    /**
     * An include names a template the schema it was written in or a global
     * declares, and gives it at least the
     * arguments the template requires. With no schema there is nothing to look
     * the template up in, and a name the text is missing was reported where it
     * was read.
     *
     * @param IncludeInvocationNode $include The include.
     */
    private function assertTemplateDeclared(IncludeInvocationNode $include, ?Vocabulary $schema, ?string $schemaKey): void
    {
        $templateKey = $include->templateKey->name;

        $writtenIn = $this->writtenIn($include, $schema, $schemaKey);

        $this->assertConstantsResolve($include->arguments, $writtenIn);

        if ($writtenIn === null || $templateKey === INode::MISSING_NAME) {
            return;
        }

        $definition = $this->constructs()->ruleTemplate($templateKey, $writtenIn)?->definition;

        if ($definition === null) {
            $this->fail(
                DiagnosticCode::UnknownTemplate,
                LanguageRuleMessages::unknownTemplate($writtenIn, $templateKey),
                $include->templateKey->location,
                LanguageRuleMessages::didYouMean($templateKey, $this->constructs()->ruleTemplateKeysFrom($writtenIn)),
            );

            return;
        }

        if (count($include->arguments) < $definition->requiredArgumentCount) {
            $this->fail(
                DiagnosticCode::TooFewArguments,
                LanguageRuleMessages::tooFewTemplateArguments(
                    $templateKey,
                    $definition->requiredArgumentCount,
                    count($include->arguments),
                ),
                $include->location,
            );
        }

        if ($definition->maxArgumentCount !== null && count($include->arguments) > $definition->maxArgumentCount) {
            $this->fail(
                DiagnosticCode::TooManyArguments,
                LanguageRuleMessages::tooManyTemplateArguments(
                    $templateKey,
                    $definition->maxArgumentCount,
                    count($include->arguments),
                ),
                $include->location,
            );
        }
    }

    private function validateRule(RuleNode $rule, ?Vocabulary $schema, ?string $schemaKey): void
    {
        foreach ([...$rule->canClauses, ...$rule->cannotClauses] as $clause) {
            $this->assertAbilitiesDeclared($clause->abilities, $schema, $this->writtenIn($rule, $schema, $schemaKey));
        }

        $this->assertAbilityListsWellWritten($rule);
        $this->assertMessagesAreMessages($rule, $this->writtenIn($rule, $schema, $schemaKey));

        if ($rule->conditions !== null) {
            $this->validateCondition($rule->conditions, $schema, $schemaKey);
        }
    }

    /**
     * Every name in an ability list must be one the schema declares, and so
     * must every name the value of a binding or constant gives it: a binding's
     * when binding values are checked, and a constant's when its value is
     * known. A bare name the schema does not declare may be a constant naming
     * abilities, looked up from $writtenIn. `*` is not a name but a stand-in for
     * all of them, so it is passed over. A binding with no value, or one whose
     * value names no abilities, is reported by the binding checks.
     *
     * @param list<NameNode|BindingNode|ConstantRef> $abilities
     * @param ISchemaId|null $writtenIn Where a constant among them is looked up
     *   from; null when that is not known.
     */
    private function assertAbilitiesDeclared(array $abilities, ?Vocabulary $schema, ?ISchemaId $writtenIn): void
    {
        foreach ($abilities as $ability) {
            if ($ability instanceof NameNode && ($schema === null || $ability->name === '*' || $schema->ability($ability->name) !== null)) {
                continue;
            }

            $names = match (true) {
                $ability instanceof BindingNode => $this->input->checkBindingValues && $ability->hasValue()
                    && AbilityMistakes::inListValue($ability, $ability->value()) === null
                        ? AbilityMistakes::namesIn($ability->value())
                        : [],
                default => $this->abilitiesNamedByConstant($ability, $schema, $writtenIn),
            };

            foreach ($schema === null ? [] : $names as $name) {
                if ($name !== '*' && $schema->ability($name) === null) {
                    $this->fail(
                        DiagnosticCode::UnknownAbility,
                        sprintf('Ability [%s] is not declared by the schema.', $name),
                        $ability->location,
                        LanguageRuleMessages::didYouMean($name, $schema->abilityNames()),
                    );
                }
            }
        }
    }

    /**
     * The abilities the constant $ability stands for in an ability list names,
     * reporting a value that names none. A bare name no constant has is
     * returned as the one ability it names, for the caller to report as one the
     * schema does not declare. Empty when there is nothing to judge: a constant
     * that names none, which is reported, or one whose value is not known.
     *
     * @return list<string>
     */
    private function abilitiesNamedByConstant(NameNode|ConstantRef $ability, ?Vocabulary $schema, ?ISchemaId $writtenIn): array
    {
        if ($ability instanceof NameNode) {
            $found = $schema === null || $writtenIn === null
                ? null
                : $this->constructs()->constantNamingAbilities($ability->name, $schema, $writtenIn)?->definition;

            if ($found === null) {
                return [$ability->name];
            }

            $ability = new ConstantRef(null, $ability, $ability->location);
        } else {
            $found = $this->resolvedConstant($ability, $writtenIn);
        }

        if ($found === null || ! $found->valueIsKnown) {
            return [];
        }

        $mistake = AbilityMistakes::inListValue($ability, $found->value);

        if ($mistake !== null) {
            $this->fail(DiagnosticCode::InvalidConstantValue, $mistake, $ability->location);

            return [];
        }

        return AbilityMistakes::namesIn($found->value);
    }

    /**
     * The ability lists $entry writes hold no ability or binding twice, and no
     * `*` beside anything else ({@see AbilityMistakes}).
     */
    private function assertAbilityListsWellWritten(IRuleEntryNode $entry): void
    {
        foreach (AbilityMistakes::inWrittenLists($entry) as [$code, $message, $location]) {
            $this->fail($code, $message, $location);
        }
    }

    /**
     * A denial message follows only a `they cannot` clause, since one after
     * `they can` could never surface, and it is text: a quoted string, a
     * binding, a constant whose value is a string, or a closure in a rule built
     * in code. A `@context` is not one, because a message is fixed before any
     * check rather than read from it. Whether a binding's value is a message is
     * the binding checks' to say.
     *
     * @param ISchemaId|null $writtenIn Where a constant naming a message is
     *   looked up from; null when that is not known.
     */
    private function assertMessagesAreMessages(RuleNode $rule, ?ISchemaId $writtenIn): void
    {
        foreach ($rule->canClauses as $clause) {
            if ($clause->message !== null) {
                $this->fail(DiagnosticCode::MessageOnCanClause, LanguageRuleMessages::messageOnCanClause(), $clause->message->location);
            }
        }

        foreach ($rule->cannotClauses as $clause) {
            $message = $clause->message;
            $isText = $message instanceof BindingNode
                || $message instanceof ConstantRef
                || ($message instanceof LiteralNode && (is_string($message->value) || $message->value instanceof Closure));

            if ($message !== null && ! $isText) {
                $this->fail(DiagnosticCode::MessageNotText, LanguageRuleMessages::messageIsNotText(), $message->location);
            }

            $constant = $message instanceof ConstantRef ? $this->resolvedConstant($message, $writtenIn) : null;

            if ($message instanceof ConstantRef && $constant !== null && $constant->valueIsKnown && ! is_string($constant->value)) {
                $this->fail(
                    DiagnosticCode::InvalidConstantValue,
                    LanguageRuleMessages::messageConstantNotText($message->written(), $constant->value),
                    $message->location,
                );
            }
        }
    }
}
