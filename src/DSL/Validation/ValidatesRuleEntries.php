<?php

namespace Warrant\DSL\Validation;

use Closure;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\SchemaVocabulary;

/**
 * The checks a {@see SyntaxValidator} makes of rule entries as written: that
 * they name their abilities, or leave them to an `@include`, as what produced
 * them says; that every ability they name and every template they include is
 * one the schema declares; and that each rule's own clauses and messages are
 * well formed. A rule's `if` expression is checked by
 * {@see ValidatesExpressions}.
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
     * @param SchemaVocabulary|null $schema The schema the entries belong to;
     *   null when it is not known, and their names are not looked up.
     * @param string|null $schemaKey The key of the schema the entries are for:
     *   the name its own rows go by in a `@column` reference, and the schema an
     *   error about an entry that names no abilities names.
     */
    private function validateEntries(array $entries, GenericMode $mode, ?SchemaVocabulary $schema, ?string $schemaKey): void
    {
        if ($mode === GenericMode::Either) {
            $this->assertNamingAgrees($entries);
        }

        foreach ($entries as $entry) {
            if ($entry instanceof AbilityBlockNode) {
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

                $this->assertAbilitiesDeclared($entry->abilities, $schema);
                $this->assertTemplateDeclared($entry, $schema, $schemaKey);
            } elseif ($entry instanceof WarrantRuleNode) {
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
                            LanguageRuleMessages::namedWhereGeneric('This clause'),
                            $clause->abilities[0]->location,
                        );
                    }
                }

                $this->validateRule($entry, $schema, $schemaKey);
            }
        }
    }

    /**
     * An ability block names at least one ability in its header, and the rules
     * and includes in its body name none: the header names them all. A block
     * inside it is reported and not read further, having no header of its own
     * to stand under.
     */
    private function validateAbilityBlock(AbilityBlockNode $block, ?SchemaVocabulary $schema, ?string $schemaKey): void
    {
        if ($block->abilities === []) {
            $this->fail(DiagnosticCode::AbilityBlockNamesNone, LanguageRuleMessages::blockNamesNone(), $block->location);
        }

        $this->assertAbilitiesDeclared($block->abilities, $schema);

        foreach ($block->entries as $entry) {
            if ($entry instanceof AbilityBlockNode) {
                $this->fail(DiagnosticCode::AbilityBlockInsideBlock, LanguageRuleMessages::blockInsideBlock(), $entry->location);
            } elseif ($entry instanceof IncludeInvocationNode) {
                if (! $entry->isGeneric()) {
                    $this->fail(
                        DiagnosticCode::AbilitiesNamedInsideBlock,
                        LanguageRuleMessages::namedInsideBlock('An @include'),
                        $entry->abilities[0]->location,
                    );
                }

                $this->assertTemplateDeclared($entry, $schema, $schemaKey);
            } elseif ($entry instanceof WarrantRuleNode) {
                foreach ([...$entry->canClauses, ...$entry->cannotClauses] as $clause) {
                    if ($clause->abilities !== []) {
                        $this->fail(
                            DiagnosticCode::AbilitiesNamedInsideBlock,
                            LanguageRuleMessages::namedInsideBlock('A clause'),
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
     * them to be named where the rules are placed: the first clause, include or
     * block decides, and each one after it that does not do the same is
     * reported.
     *
     * @param list<IRuleEntryNode> $entries
     */
    private function assertNamingAgrees(array $entries): void
    {
        $first = null;

        foreach ($entries as $entry) {
            $named = match (true) {
                $entry instanceof AbilityBlockNode => [['An ability block', true, $entry]],
                $entry instanceof IncludeInvocationNode => [['This @include', ! $entry->isGeneric(), $entry]],
                $entry instanceof WarrantRuleNode => array_map(
                    static fn (CanClauseNode|CannotClauseNode $clause): array => ['This clause', $clause->abilities !== [], $clause],
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

    /**
     * An include names a template the schema declares, and gives it at least the
     * arguments the template requires. With no schema there is nothing to look
     * the template up in, and a name the text is missing was reported where it
     * was read.
     *
     * @param IncludeInvocationNode $include The include.
     */
    private function assertTemplateDeclared(IncludeInvocationNode $include, ?SchemaVocabulary $schema, ?string $schemaKey): void
    {
        $templateKey = $include->templateKey->name;

        if ($schema === null || $schemaKey === null || $templateKey === INode::MISSING_NAME) {
            return;
        }

        $definition = $schema->getRuleTemplateDefinition($templateKey);

        if ($definition === null) {
            $this->fail(
                DiagnosticCode::UnknownTemplate,
                LanguageRuleMessages::unknownTemplate($schemaKey, $templateKey),
                $include->templateKey->location,
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
    }

    private function validateRule(WarrantRuleNode $rule, ?SchemaVocabulary $schema, ?string $schemaKey): void
    {
        foreach ([...$rule->canClauses, ...$rule->cannotClauses] as $clause) {
            $this->assertAbilitiesDeclared($clause->abilities, $schema);
        }

        $this->assertNoDuplicateCannotAbility($rule);
        $this->assertMessagesAreMessages($rule);

        if ($rule->conditions !== null) {
            $this->validateCondition($rule->conditions, $schema, $schemaKey);
        }
    }

    /**
     * Every name in an ability list must be one the schema declares. `*` is not a
     * name but a stand-in for all of them, so it is passed over, and so is a
     * binding, whose value is not written in the text.
     *
     * @param list<NameNode|BindingNode> $abilities
     */
    private function assertAbilitiesDeclared(array $abilities, ?SchemaVocabulary $schema): void
    {
        if ($schema === null) {
            return;
        }

        foreach ($abilities as $ability) {
            if ($ability instanceof NameNode && $ability->name !== '*' && $schema->getAbilityDefinition($ability->name) === null) {
                $this->fail(
                    DiagnosticCode::UnknownAbility,
                    sprintf('Ability [%s] is not declared by the schema.', $ability->name),
                    $ability->location,
                );
            }
        }
    }

    /**
     * An ability may appear in at most one `cannot` clause of a rule. A duplicate
     * would give that ability two denial messages, of which only the first could
     * ever surface (see {@see WarrantRuleNode::messageFor()}), so it is almost always
     * a mistake — reject it rather than silently dropping the later message.
     */
    private function assertNoDuplicateCannotAbility(WarrantRuleNode $rule): void
    {
        $seen = [];

        foreach ($rule->cannotClauses as $clause) {
            foreach ($clause->abilities as $ability) {
                if ($ability instanceof BindingNode) {
                    continue;
                }

                if (isset($seen[$ability->name])) {
                    $this->fail(
                        DiagnosticCode::DuplicateCannotAbility,
                        sprintf(
                        'Ability [%s] appears in more than one `they cannot ...` clause of the same rule; list it once.',
                        $ability->name,
                    ),
                        $ability->location,
                    );
                }

                $seen[$ability->name] = true;
            }
        }
    }

    /**
     * A denial message follows only a `they cannot` clause, since one after
     * `they can` could never surface, and it is text: a quoted string or a
     * binding, or a closure in a rule built in code. A `@context` is not one,
     * because a message is fixed before any check rather than read from it.
     * Whether a binding's value is a message is the binding checks' to say.
     */
    private function assertMessagesAreMessages(WarrantRuleNode $rule): void
    {
        foreach ($rule->canClauses as $clause) {
            if ($clause->message !== null) {
                $this->fail(DiagnosticCode::MessageOnCanClause, LanguageRuleMessages::messageOnCanClause(), $clause->message->location);
            }
        }

        foreach ($rule->cannotClauses as $clause) {
            $message = $clause->message;
            $isText = $message instanceof BindingNode
                || ($message instanceof LiteralNode && (is_string($message->value) || $message->value instanceof Closure));

            if ($message !== null && ! $isText) {
                $this->fail(DiagnosticCode::MessageNotText, LanguageRuleMessages::messageIsNotText(), $message->location);
            }
        }
    }
}
