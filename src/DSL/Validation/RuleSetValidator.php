<?php

namespace Warrant\DSL\Validation;

use Closure;
use InvalidArgumentException;
use Warrant\DSL\Compiling\AliasScope;
use Warrant\DSL\ConditionResolver;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SourcePosition;
use Warrant\DSL\SchemaCatalog;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Registry\RegisteredSchemas;

/**
 * Validates every condition and ability name in a {@see RuleSetNode} against
 * the schema it targets — including that each condition is called with at least
 * as many arguments as it requires.
 *
 * ## What this is for
 *
 * Early feedback, not safety. A name that resolves to nothing is rejected by
 * {@see \Warrant\DSL\Compiling\RuleSetCompiler} at the lookup that needs it, so
 * nothing depends on this class having run: skip it and a broken rule still fails
 * loudly, just later and against a live user and query.
 *
 * What it adds is reach in the other direction. It answers from rule text alone —
 * no database, no user, no query — which is what a language server or a CI check
 * needs, and it sees every rule in a set rather
 * than only the paths a particular check happens to compile.
 *
 * It reads rules as their author wrote them, one text at a time. The rules a
 * template answers with and the expression a derived condition answers with are
 * texts of their own, validated by {@see RuleSetExpander} before it splices them
 * in, so nothing a compile could reach is out of sight. Expansion reads no user,
 * row or check context, so neither does this.
 *
 * Own-schema checks depend only on the schema's {@see SchemaVocabulary} — name
 * existence, no SQL. A cross-schema `can(...)` reference is additionally resolved
 * against the registry (by schema key) to confirm the target schema and ability
 * exist; the referenced schema's *rules* are never consulted here (they are
 * per-user and resolver-owned), so cycle detection is deliberately left to the
 * compiler, not this validator. That is also why a reference may target the
 * owning schema: whether it recurses is a question about rules this validator
 * cannot see.
 *
 * One check is not about names but about *scope*: a `@column` reference may only
 * name a table the rule can actually see from where it is written. That set grows
 * as the walk descends into a `check(...)` predicate, mirroring the
 * {@see \Warrant\DSL\Compiling\AliasScope} the compiler builds, so a reference
 * to an unrelated table is an error here rather than a SQL error at execution.
 *
 * ## Where an error points
 *
 * Given the {@see WarrantSyntax} the rules were parsed from, an error says where
 * in that text it is: at the ability it is about, or else the innermost node it
 * was found in that the text holds. A rule the text does not hold — one built in
 * code, or put in place by expansion — has nowhere to point, and its error is
 * placed at the nearest node around it that the text does hold, if any.
 *
 * Every mistake is reported, none thrown: {@see SyntaxValidator} gathers them
 * for {@see \Warrant\DSL\Parsing\ASTNodes\ValidSyntax} to throw or keep.
 *
 * @internal
 */
final class RuleSetValidator
{
    /**
     * @param Closure(InvalidArgumentException): void $report Called with each
     *   mistake found, after which the walk goes on to the next thing to check.
     * @param SchemaVocabulary|null $schema The schema the rules belong to; null
     *   when that is not known, as for rules with no `for` header read with no
     *   schema given. The rules are then held to the rules of the language that
     *   need no vocabulary, and their names are not looked up.
     * @param string|null $schemaKey The owning schema's key — the name its own
     *   rows go by in a `@column` reference; null with no schema.
     * @param WarrantSyntax|null $writtenIn The text the rules were parsed from,
     *   for errors to point into; null for rules built in code.
     * @param list<ColumnRef> $callerColumnRefs The `@column` references the
     *   calling rule passed in; see {@see ValidationInput::$callerColumnRefs}.
     * @param SchemaCatalog $catalog Where the schemas a `can(... for <schema>)`
     *   or `check(... for <schema>)` names are found.
     */
    public function __construct(
        private readonly Closure $report,
        private readonly ?SchemaVocabulary $schema,
        private readonly ?string $schemaKey,
        private readonly ?WarrantSyntax $writtenIn = null,
        private readonly array $callerColumnRefs = [],
        private readonly SchemaCatalog $catalog = new RegisteredSchemas,
    ) {
    }

    /**
     * Validate a rule set as written: every rule, and the abilities every block
     * header and include names. Entries in a rule set name their abilities,
     * every one outside an ability block, since nothing else names them.
     *
     * Those abilities are checked here, where they are written, because the
     * expansion only carries them on the rules they end up on, and an empty block
     * or a template with an empty body puts them on none. So is an include naming
     * a template the schema does not declare, or giving it too few arguments,
     * which the expansion rejects the same way before it asks the template for
     * anything.
     */
    public function validateWritten(RuleSetNode $ruleSet): void
    {
        $this->validateEntriesFor($ruleSet->entries, GenericMode::RequireNotGeneric, $ruleSet->schemaKey);
    }

    /**
     * Validate rule entries with no `for` header as written, as
     * {@see validateWritten()} does, with $mode saying whether they name their
     * abilities.
     *
     * @param list<IRuleEntryNode> $entries
     */
    public function validateEntries(array $entries, GenericMode $mode): void
    {
        $this->validateEntriesFor($entries, $mode, $this->schemaKey);
    }

    /**
     * @param list<IRuleEntryNode> $entries
     * @param string|null $ruleSetKey The schema the entries are for, for an error
     *   about an entry that names no abilities.
     */
    private function validateEntriesFor(array $entries, GenericMode $mode, ?string $ruleSetKey): void
    {
        if ($mode === GenericMode::Either) {
            $this->assertNamingAgrees($entries);
        }

        foreach ($entries as $entry) {
            if ($entry instanceof AbilityBlockNode) {
                if ($mode === GenericMode::RequireGeneric) {
                    $this->fail(new RuleValidationException(DiagnosticCode::AbilitiesNamedWhereGeneric, LanguageRuleMessages::namedWhereGeneric('An ability block')), $entry);
                }

                $this->validateAbilityBlock($entry);
            } elseif ($entry instanceof IncludeInvocationNode) {
                if ($mode === GenericMode::RequireNotGeneric && $entry->isGeneric()) {
                    $this->fail(new RuleValidationException(DiagnosticCode::AbilitiesUnnamedOutsideBlock, LanguageRuleMessages::unnamedOutsideBlock($ruleSetKey)), $entry);
                }

                if ($mode === GenericMode::RequireGeneric && ! $entry->isGeneric()) {
                    $this->fail(
                        new RuleValidationException(DiagnosticCode::AbilitiesNamedWhereGeneric, LanguageRuleMessages::namedWhereGeneric('This @include')),
                        $entry,
                        IncludeInvocationNode::PART_ABILITIES,
                        0,
                    );
                }

                $this->assertAbilitiesDeclared($entry->abilities, $entry, IncludeInvocationNode::PART_ABILITIES);
                $this->assertTemplateDeclared($entry);
            } elseif ($entry instanceof WarrantRuleNode) {
                foreach ([...$entry->canClauses, ...$entry->cannotClauses] as $clause) {
                    if ($mode === GenericMode::RequireNotGeneric && $clause->abilities === []) {
                        $this->fail(new RuleValidationException(DiagnosticCode::AbilitiesUnnamedOutsideBlock, LanguageRuleMessages::unnamedOutsideBlock($ruleSetKey)), $clause);
                    }

                    if ($mode === GenericMode::RequireGeneric && $clause->abilities !== []) {
                        $this->fail(
                            new RuleValidationException(DiagnosticCode::AbilitiesNamedWhereGeneric, LanguageRuleMessages::namedWhereGeneric('This clause')),
                            $clause,
                            $clause instanceof CanClauseNode ? CanClauseNode::PART_ABILITIES : CannotClauseNode::PART_ABILITIES,
                            0,
                        );
                    }
                }

                $this->validateRule($entry);
            }
        }
    }

    /**
     * An ability block names at least one ability in its header, and the rules
     * and includes in its body name none: the header names them all. A block
     * inside it is reported and not read further, having no header of its own
     * to stand under.
     */
    private function validateAbilityBlock(AbilityBlockNode $block): void
    {
        if ($block->abilities === []) {
            $this->fail(new RuleValidationException(DiagnosticCode::AbilityBlockNamesNone, LanguageRuleMessages::blockNamesNone()), $block);
        }

        $this->assertAbilitiesDeclared($block->abilities, $block, AbilityBlockNode::PART_ABILITIES);

        foreach ($block->entries as $entry) {
            if ($entry instanceof AbilityBlockNode) {
                $this->fail(new RuleValidationException(DiagnosticCode::AbilityBlockInsideBlock, LanguageRuleMessages::blockInsideBlock()), $entry);
            } elseif ($entry instanceof IncludeInvocationNode) {
                if (! $entry->isGeneric()) {
                    $this->fail(
                        new RuleValidationException(DiagnosticCode::AbilitiesNamedInsideBlock, LanguageRuleMessages::namedInsideBlock('An @include')),
                        $entry,
                        IncludeInvocationNode::PART_ABILITIES,
                        0,
                    );
                }

                $this->assertTemplateDeclared($entry);
            } elseif ($entry instanceof WarrantRuleNode) {
                foreach ([...$entry->canClauses, ...$entry->cannotClauses] as $clause) {
                    if ($clause->abilities !== []) {
                        $this->fail(
                            new RuleValidationException(DiagnosticCode::AbilitiesNamedInsideBlock, LanguageRuleMessages::namedInsideBlock('A clause')),
                            $clause,
                            $clause instanceof CanClauseNode ? CanClauseNode::PART_ABILITIES : CannotClauseNode::PART_ABILITIES,
                            0,
                        );
                    }
                }

                $this->validateRule($entry);
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
                    $this->fail(new RuleValidationException(DiagnosticCode::AbilityNamingDisagrees, LanguageRuleMessages::namingDisagrees($what, $names)), $node);
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
    private function assertTemplateDeclared(IncludeInvocationNode $include): void
    {
        if ($this->schema === null || $this->schemaKey === null || $include->templateKey === INode::MISSING_NAME) {
            return;
        }

        $definition = $this->schema->getRuleTemplateDefinition($include->templateKey);

        if ($definition === null) {
            $this->fail(
                new RuleValidationException(DiagnosticCode::UnknownTemplate, LanguageRuleMessages::unknownTemplate($this->schemaKey, $include->templateKey)),
                $include,
                IncludeInvocationNode::PART_TEMPLATE_KEY,
            );

            return;
        }

        if (count($include->arguments) < $definition->requiredArgumentCount) {
            $this->fail(
                new RuleValidationException(DiagnosticCode::TooFewArguments, LanguageRuleMessages::tooFewTemplateArguments(
                    $include->templateKey,
                    $definition->requiredArgumentCount,
                    count($include->arguments),
                )),
                $include,
            );
        }
    }

    private function validateRule(WarrantRuleNode $rule): void
    {
        try {
            foreach ([...$rule->canClauses, ...$rule->cannotClauses] as $clause) {
                $this->assertAbilitiesDeclared(
                    $clause->abilities,
                    $clause,
                    $clause instanceof CanClauseNode ? CanClauseNode::PART_ABILITIES : CannotClauseNode::PART_ABILITIES,
                );
            }

            $this->assertNoDuplicateCannotAbility($rule);
            $this->assertMessagesAreMessages($rule);

            if ($rule->conditions !== null && $this->schema !== null) {
                $this->validateExpression($rule->conditions, $this->schema, $this->rootScope());
            }
        } catch (InvalidArgumentException $e) {
            $this->fail($e, $rule);
        }
    }

    /**
     * Validate every name in a condition written under a `for <schema>` header
     * against the schema, as a rule's own `if` expression would be.
     */
    public function validateCondition(SchemaConditionNode $condition): void
    {
        if ($this->schema !== null) {
            $this->validateExpression($condition->expression, $this->schema, $this->rootScope());
        }
    }

    /**
     * Every name in an ability list must be one the schema declares. `*` is not a
     * name but a stand-in for all of them, so it is passed over.
     *
     * @param list<string> $abilities The ability list $part of $owner.
     */
    private function assertAbilitiesDeclared(array $abilities, object $owner, string $part): void
    {
        if ($this->schema === null) {
            return;
        }

        foreach ($abilities as $index => $ability) {
            if ($ability !== '*' && $this->schema->getAbilityDefinition($ability) === null) {
                $this->fail(
                    new RuleValidationException(DiagnosticCode::UnknownAbility, sprintf('Ability [%s] is not declared by the schema.', $ability)),
                    $owner,
                    $part,
                    $index,
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
            foreach ($clause->abilities as $index => $ability) {
                if (isset($seen[$ability])) {
                    $this->fail(new RuleValidationException(DiagnosticCode::DuplicateCannotAbility, sprintf(
                        'Ability [%s] appears in more than one `they cannot ...` clause of the same rule; list it once.',
                        $ability,
                    )), $clause, CannotClauseNode::PART_ABILITIES, $index);
                }

                $seen[$ability] = true;
            }
        }
    }

    /**
     * $error, placed where $node (or its $part, at $key) was written in the text
     * these rules were parsed from. An error already placed, further in, keeps
     * its place; one about a node the text does not hold is left for a node
     * further out to place.
     */
    private function placed(
        InvalidArgumentException $error,
        object $node,
        ?string $part = null,
        int|string|null $key = null,
    ): InvalidArgumentException {
        if ($error instanceof RuleValidationException && $error->position !== null) {
            return $error;
        }

        $span = $this->writtenIn?->positions?->spanOf($node, $part, $key);

        if ($span === null || $this->writtenIn->source === null) {
            return $error;
        }

        $reason = $error instanceof RuleValidationException ? $error->reason : $error->getMessage();
        $code = $error instanceof RuleValidationException ? $error->diagnosticCode : DiagnosticCode::InvalidRule;

        return new RuleValidationException($code, $reason, SourcePosition::at($this->writtenIn->source, $span->offset), $error, $span);
    }

    /**
     * Report $error, placed as {@see placed()} places it, so the walk goes on to
     * the next thing to check.
     */
    private function fail(
        InvalidArgumentException $error,
        object $node,
        ?string $part = null,
        int|string|null $key = null,
    ): void {
        ($this->report)($this->placed($error, $node, $part, $key));
    }

    /**
     * A denial message follows only a `they cannot` clause, since one after
     * `they can` could never surface, and it is text: a quoted string or a
     * binding, or a closure in a rule built in code. A `@context` is not one,
     * because a message is fixed before any check rather than read from it.
     */
    private function assertMessagesAreMessages(WarrantRuleNode $rule): void
    {
        foreach ($rule->canClauses as $clause) {
            if ($clause->message !== null) {
                $this->fail(new RuleValidationException(DiagnosticCode::MessageOnCanClause, LanguageRuleMessages::messageOnCanClause()), $clause, CanClauseNode::PART_MESSAGE);
            }
        }

        foreach ($rule->cannotClauses as $clause) {
            $message = $clause->message;

            if ($message !== null && ! is_string($message) && ! $message instanceof Closure && ! $message instanceof BindingNode) {
                $this->fail(new RuleValidationException(DiagnosticCode::MessageNotText, LanguageRuleMessages::messageIsNotText()), $clause, CannotClauseNode::PART_MESSAGE);
            }
        }
    }

    /**
     * The scope at the top of these rules: the owning schema's own key, standing
     * for the row being checked, bound to no qualifier because no query is in
     * hand here.
     *
     * A capability schema is the exception — it has no model and so no table, so
     * its own key names nothing and a `@column` in its rules has nothing in scope
     * at all. The vocabulary contract does not expose a model, so this asks the
     * richer {@see ConditionResolver} when it has one and otherwise assumes rows.
     */
    private function rootScope(): AliasScope
    {
        $rowless = ! $this->schema->describesRows();

        return $rowless ? AliasScope::none() : AliasScope::root($this->schemaKey, null);
    }

    /**
     * Walk one boolean expression, checking every name in it.
     *
     * The same walk serves a rule's own expression and a `check(...)` predicate,
     * which differ only in what they are *about*: a predicate's leaves belong to
     * the schema its handle names, and its frame may have no row. Both travel as
     * parameters, so descending into a predicate is the walk calling itself with a
     * different vocabulary rather than a second walk with its own rules.
     *
     * @param SchemaVocabulary $vocabulary Whose conditions and abilities the leaves
     *   of $node name.
     * @param AliasScope $scope The frames in scope where $node is written; see
     *   {@see assertColumnRefsInScope}. Every qualifier in it is null — nothing is
     *   selected until a compile, and only the names matter here.
     * @param CrossSchemaConditionNode|null $predicateOf The `check(...)` whose
     *   predicate this is, or null for a rule's own expression.
     */
    private function validateExpression(
        IBooleanExpressionNode $node,
        SchemaVocabulary $vocabulary,
        AliasScope $scope,
        ?CrossSchemaConditionNode $predicateOf = null,
    ): void {
        try {
            $this->validateExpressionNode($node, $vocabulary, $scope, $predicateOf);
        } catch (InvalidArgumentException $e) {
            $this->fail($e, $node);
        }
    }

    /**
     * {@see validateExpression()} for $node itself, its errors not yet placed.
     */
    private function validateExpressionNode(
        IBooleanExpressionNode $node,
        SchemaVocabulary $vocabulary,
        AliasScope $scope,
        ?CrossSchemaConditionNode $predicateOf,
    ): void {
        match (true) {
            $node instanceof ConditionNode => $this->assertConditionValid($node, $vocabulary, $scope, $predicateOf),
            $node instanceof CrossSchemaCanNode => $this->assertCrossSchemaCanValid($node, $vocabulary, $scope),
            $node instanceof CrossSchemaConditionNode => $this->assertCrossSchemaConditionValid($node, $scope),
            $node instanceof NotNode => $this->validateExpression($node->operand, $vocabulary, $scope, $predicateOf),
            $node instanceof AndNode, $node instanceof OrNode => (function () use ($node, $vocabulary, $scope, $predicateOf): void {
                $this->validateExpression($node->leftSide, $vocabulary, $scope, $predicateOf);
                $this->validateExpression($node->rightSide, $vocabulary, $scope, $predicateOf);
            })(),
            // The analysis that read it in place of a missing expression reported it.
            $node instanceof MissingExpressionNode => null,
            /* A rule may be written around a constant; a predicate may not, because
               a `check(...)` that decides itself asks the target nothing. A derived
               condition answering with a constant or unknown is another matter: its
               answer is validated on its own, where no predicate surrounds it. */
            default => $predicateOf === null ? null : throw new RuleValidationException(DiagnosticCode::ConstantInCheckPredicate, sprintf(
                'A check(...) predicate for schema [%s] may not contain a constant; it has to ask that '
                    .'schema something.',
                $predicateOf->schemaKey,
            )),
        };
    }

    /**
     * Validate a cross-schema `can(<ability> for <schema>[(<row>)] [as <alias>])`
     * reference: the target schema must be registered, the ability must be
     * declared by it, a row-bound reference requires a model-backed target (a
     * capability schema has no row to target), and an alias requires a row to
     * name.
     *
     * The target may be this schema itself. Two frames over one table are told
     * apart by naming the hop's rows (`as d2`), and recursion is bounded by
     * {@see \Warrant\DSL\Compiling\CallStack}, which rejects re-entering an
     * ability already in progress and caps depth for everything else — so a
     * self-reference has to name a *different* ability to compile at all.
     */
    private function assertCrossSchemaCanValid(
        CrossSchemaCanNode $node,
        SchemaVocabulary $vocabulary,
        AliasScope $scope,
    ): void {
        if ($node->schemaKey === null) {
            $this->assertOwnAbilityValid($node, $vocabulary);

            return;
        }

        // A handle with no name was reported where it was read; there is no schema to look up.
        if ($node->schemaKey === INode::MISSING_NAME) {
            return;
        }

        $target = $this->catalog->find($node->schemaKey) ?? throw new RuleValidationException(DiagnosticCode::UnknownSchema, 
            sprintf('A can(...) reference targets unknown schema [%s].', $node->schemaKey),
        );

        if ($node->ability !== INode::MISSING_NAME && $target->getAbilityDefinition($node->ability) === null) {
            throw new RuleValidationException(DiagnosticCode::UnknownAbility, sprintf(
                'Ability [%s] is not declared by schema [%s].',
                $node->ability,
                $node->schemaKey,
            ));
        }

        if ($node->isRowBound && ! $target->describesRows()) {
            throw new RuleValidationException(DiagnosticCode::RowTargetOnRowlessSchema, sprintf(
                'A can(...) reference targets a specific row of schema [%s], but [%s] has no rows and cannot be row-targeted; drop the row selector.',
                $node->schemaKey,
                $node->schemaKey,
            ));
        }

        $this->assertTargetCanNameARow('can', $node->schemaKey, $target, $node->isRowBound);
        $this->assertKeyArityIsSatisfied('can', $node->schemaKey, $target, $node->isRowBound, $node->boundKey);

        // Every argument of a specified row target must resolve to a value. A
        // literal `null` (or a `:name`/`?` binding that resolved to null) can
        // never match a row, so it is a mistake rather than a valid selector;
        // reject it here, in any position. A `@context` reference is a symbolic
        // ContextRef, not null — its value is filled per check, so its
        // nullability stays a compile-time concern, not a static one.
        if ($node->isRowBound && in_array(null, $node->boundKey, true)) {
            throw new RuleValidationException(DiagnosticCode::NullRowTarget, sprintf(
                'A can(...) reference to schema [%s] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.',
                $node->schemaKey,
            ));
        }

        $this->assertAliasHasARow('can', $node->schemaKey, $node->isRowBound, $node->alias);

        /* The handle's own arguments are written in the enclosing rule, so they see
           the enclosing scope. The target's *rules* are not validated here at all —
           they are validated against their own schema, with their own scope. */
        $this->assertColumnRefsInScope([...$node->boundKey, ...array_values($node->contextMap)], $scope);
    }

    /**
     * Validate a cross-schema
     * `check(<predicate> for <schema>[(<row>)] [as <alias>])` reference: the
     * target schema must be registered, a row-bound reference requires a
     * model-backed target with a non-null row, and an alias requires a row to
     * name. The predicate is a boolean expression read against the *target*
     * schema's vocabulary — so a `can(...)` in it names one of that schema's
     * abilities, and a nested `check(...)` starts from that schema's frame. An
     * unbound handle constrains the predicate's leaves not at all: a row condition
     * among them has no row and so no answer, which the compiler says with an
     * unknown rather than an error (see {@see assertConditionValid}).
     *
     * As with `can(...)` the target may be this schema itself.
     */
    private function assertCrossSchemaConditionValid(CrossSchemaConditionNode $node, AliasScope $scope): void
    {
        // A handle with no name was reported where it was read; there is no schema to check the predicate against.
        if ($node->schemaKey === INode::MISSING_NAME) {
            return;
        }

        $target = $this->catalog->find($node->schemaKey) ?? throw new RuleValidationException(DiagnosticCode::UnknownSchema, 
            sprintf('A check(...) reference targets unknown schema [%s].', $node->schemaKey),
        );

        if ($node->isRowBound && ! $target->describesRows()) {
            throw new RuleValidationException(DiagnosticCode::RowTargetOnRowlessSchema, sprintf(
                'A check(...) reference targets a specific row of schema [%s], but [%s] has no rows and cannot be row-targeted; drop the row selector.',
                $node->schemaKey,
                $node->schemaKey,
            ));
        }

        $this->assertTargetCanNameARow('check', $node->schemaKey, $target, $node->isRowBound);
        $this->assertKeyArityIsSatisfied('check', $node->schemaKey, $target, $node->isRowBound, $node->boundKey);

        // Every argument of a specified row target must resolve to a value; a
        // literal `null` (or a binding that resolved to null) can never match a
        // row, in any position. A `@context` reference is a symbolic ContextRef,
        // not null — filled per check — so its nullability stays a compile-time
        // concern, not a static one. (Same as can.)
        if ($node->isRowBound && in_array(null, $node->boundKey, true)) {
            throw new RuleValidationException(DiagnosticCode::NullRowTarget, sprintf(
                'A check(...) reference to schema [%s] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.',
                $node->schemaKey,
            ));
        }

        $this->assertAliasHasARow('check', $node->schemaKey, $node->isRowBound, $node->alias);

        $this->assertColumnRefsInScope([...$node->boundKey, ...array_values($node->contextMap)], $scope);

        /* Unlike a can(...), the predicate is written right here, in the enclosing
           rule — so it keeps the enclosing scope and gains the target's frame on
           top, under its alias when it has one, exactly as the compiler's
           AliasScope does. Aliasing the target therefore leaves its schema key
           still meaning the enclosing frame, which is how a predicate over two
           frames of one table tells them apart.

           A target with no model has no table, so its name is not bound at all
           rather than bound to nothing: there is no frame for a later compile to
           put in scope, which makes a `@column` naming it a mistake rather than a
           row out of reach. */
        $this->validateExpression(
            $node->predicate,
            $target,
            ! $target->describesRows()
                ? $scope->enteringRowlessPredicate()
                : $scope->enteringPredicate($node->schemaKey, $node->alias, null),
            $node,
        );
    }

    /**
     * Validate a `can(<ability>)` with no `for` clause: the ability has to be one
     * the frame's own schema declares, and nothing may be passed across a boundary
     * that is not being crossed.
     *
     * The registry is not consulted at all. There is no schema key to look up —
     * the reference stays on whichever schema the expression is about, whose
     * vocabulary arrives as $vocabulary. Inside a `check(...)` predicate that is
     * the schema the handle named, not the one the rule is written on.
     */
    private function assertOwnAbilityValid(CrossSchemaCanNode $node, SchemaVocabulary $vocabulary): void
    {
        // An ability with no name was reported where it was read.
        if ($node->ability !== INode::MISSING_NAME && $vocabulary->getAbilityDefinition($node->ability) === null) {
            throw new RuleValidationException(DiagnosticCode::UnknownAbility, sprintf(
                'Ability [%s] is not declared by the schema.',
                $node->ability,
            ));
        }

        if ($node->contextMap !== []) {
            throw new RuleValidationException(DiagnosticCode::ContextMapWithoutSchema, sprintf(
                'A can(%s) reference carries the context it is already in, so it takes no `with` map; '
                    .'name a schema with `for` if you meant to cross to one.',
                $node->ability,
            ));
        }

        if ($node->alias !== null) {
            throw new RuleValidationException(DiagnosticCode::AliasWithoutRow, sprintf(
                'A can(%s) reference selects no rows of its own, so there is nothing for [as %s] to name; '
                    .'name a schema with `for` if you meant to cross to one.',
                $node->ability,
                $node->alias,
            ));
        }
    }

    /**
     * An `as <alias>` names the rows a reference selects. An unbound handle selects
     * none — it emits no `from` at all — so an alias there names nothing, and is
     * far more likely a forgotten row selector than a deliberate no-op.
     */
    private function assertAliasHasARow(
        string $builtin,
        string $schemaKey,
        bool $isRowBound,
        ?string $alias,
    ): void {
        if ($alias === null || $isRowBound) {
            return;
        }

        throw new RuleValidationException(DiagnosticCode::AliasWithoutRow, sprintf(
            'A %s(...) reference to schema [%s] is aliased [as %s] but selects no row, so the alias names '
                .'nothing; add a row selector like %s(@context id) as %s, or drop the alias.',
            $builtin,
            $schemaKey,
            $alias,
            $schemaKey,
            $alias,
        ));
    }

    /**
     * A row-bound handle needs a target whose rows can be named.
     *
     * A virtual table may declare neither a key column nor a `matchKey()`, which
     * leaves its rows filterable but not addressable. Reported here so the rule
     * says what is wrong with it, rather than failing later on a key column that
     * was never there. The compiler makes the same check, for the handles that
     * never pass through the parser.
     *
     */
    private function assertTargetCanNameARow(
        string $builtin,
        string $schemaKey,
        SchemaVocabulary $target,
        bool $isRowBound,
    ): void {
        if (! $isRowBound || ! $target->describesRows() || $target->canNameARow()) {
            return;
        }

        throw new RuleValidationException(DiagnosticCode::UnnameableRow, sprintf(
            'A %s(...) reference targets a specific row of schema [%s], but [%s] has no way to name one; '
                .'declare `const key` for the column its rows are identified by, or a matchKey() of its '
                .'own, or drop the row selector.',
            $builtin,
            $schemaKey,
            $schemaKey,
        ));
    }

    /**
     * A row-bound handle must supply every argument the target schema's row key
     * requires — the parameters of its
     * {@see \Warrant\Schema\WarrantSchema::matchKey()} that have no default.
     *
     * Reported here so a handle written as rule text names its mistake before
     * anything is compiled. The key's own dispatch makes the same check, for the
     * handles that never pass through the parser.
     *
     * Extra arguments are allowed, exactly as they are for a condition: they are
     * ignored by the call and stay reachable on `$c->arguments`.
     *
     * @param array<int, mixed> $boundKey
     */
    private function assertKeyArityIsSatisfied(
        string $builtin,
        string $schemaKey,
        SchemaVocabulary $target,
        bool $isRowBound,
        array $boundKey,
    ): void {
        if (! $isRowBound) {
            return;
        }

        $required = $target->getKeyDefinition()->requiredArgumentCount;

        if (count($boundKey) >= $required) {
            return;
        }

        throw new RuleValidationException(DiagnosticCode::TooFewArguments, sprintf(
            'A %s(...) reference to schema [%s] supplies %d row-key argument(s), but that schema\'s row '
                .'key requires at least %d.',
            $builtin,
            $schemaKey,
            count($boundKey),
            $required,
        ));
    }

    /**
     * Validate one condition leaf: the vocabulary it is being read against has to
     * declare it, it has to be called with at least the arguments it requires, and
     * its `@column` references have to name tables in scope.
     *
     * Whether the leaf is a row condition is deliberately not checked, even under
     * a handle that selects no row. A row condition with no row has an undefined
     * answer, and the compiler gives it one — an unknown, which neither grants nor
     * lifts a deny. Rejecting it instead would make a predicate's legality depend
     * on which of its leaves happen to be row conditions, so
     * `check(is_advisor or is_owner for folders)` would be an error over its second
     * leaf alone. Row-ness is a schema's own implementation detail, invisible in
     * the rule text and free to change, so no rule is written against it.
     *
     * @param AliasScope $scope The frames in scope where $node is written.
     */
    private function assertConditionValid(
        ConditionNode $node,
        SchemaVocabulary $vocabulary,
        AliasScope $scope,
        ?CrossSchemaConditionNode $predicateOf,
    ): void {
        $definition = $vocabulary->getConditionDefinition($node->conditionKey);

        if ($definition === null) {
            throw new RuleValidationException(DiagnosticCode::UnknownCondition, $predicateOf === null
                ? sprintf('Condition [%s] is not declared by the schema.', $node->conditionKey)
                : sprintf(
                    'Condition [%s] is not declared by schema [%s].',
                    $node->conditionKey,
                    $predicateOf->schemaKey,
                ));
        }

        $this->assertEnoughArguments($node, $definition->requiredArgumentCount);
        $this->assertColumnRefsInScope($node->parameters, $scope);

        /* Context keys need no declaration: a rule may reference any `@context`
           key. An absent key simply makes its condition false at compile time
           (see RuleSetCompiler); required keys are enforced separately, at check
           time, via #[RequiredContext] and per-ability requires. */
    }

    /**
     * A condition may declare its DSL arguments as method parameters after the
     * leading context object; those without a default are required. Supplying
     * fewer arguments than required is a rule-level mistake, caught here before
     * compilation. (More arguments than parameters is allowed — the extras remain
     * reachable via the condition's `$c->arguments`.)
     */
    private function assertEnoughArguments(ConditionNode $node, int $required): void
    {
        $supplied = count($node->parameters);

        if ($supplied < $required) {
            throw new RuleValidationException(DiagnosticCode::TooFewArguments, sprintf(
                'Condition [%s] requires at least %d argument(s), but the rule supplied %d.',
                $node->conditionKey,
                $required,
                $supplied,
            ));
        }
    }

    /**
     * Eagerly validate every qualified `@column <name>.<column>` reference among a
     * set of argument values: the name must be one of the tables in scope where
     * the reference is written — the owning schema's own key, or a frame a
     * surrounding `check(...)` put in scope.
     *
     * An *unqualified* `@column <column>` names no frame, so there is nothing here
     * to be wrong about: it means whichever rows the enclosing rule is already
     * about, which is settled per compile rather than per rule. Whether those rows
     * are in scope at all is a compile-time question — a no-target compile has
     * none — and the compiler answers it by folding the leaf to unknown, not by
     * erroring, because the same rule works wherever a row *is* in scope.
     *
     * The scope is the compiler's own {@see \Warrant\DSL\Compiling\AliasScope},
     * carrying names bound to no qualifier, so one implementation answers what is
     * in scope for both walks and a reference that cannot work fails here rather
     * than emitting SQL about a table the query never joined. Referencing
     * your own rows is the ordinary case and always allowed (unlike
     * `can(...)`/`check(...)`, whose whole point is to leave the schema). The column
     * name itself is not checked — there is no column introspection.
     * Non-{@see ColumnRef} values are ignored.
     *
     * @param array<int, mixed> $arguments
     * @param AliasScope $scope The frames in scope where these arguments are
     *   written.
     */
    private function assertColumnRefsInScope(array $arguments, AliasScope $scope): void
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof ColumnRef && ! in_array($argument, $this->callerColumnRefs, true)) {
                /* The scope itself decides, and throws the message: an unbound name
                   is the mistake, and a name bound to nothing is a frame no compile
                   selected here, which is not. A null alias asks about this frame's
                   own rows and is answered without a name at all. */
                try {
                    $scope->resolveColumn($argument);
                } catch (InvalidArgumentException $e) {
                    throw new RuleValidationException(DiagnosticCode::ColumnNotInScope, $e->getMessage(), previous: $e);
                }
            }
        }
    }
}
