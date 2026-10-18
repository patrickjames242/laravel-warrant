<?php

namespace Warrant\DSL\Expanding;

use Closure;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SyntaxBindingsResolved;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Validation\GenericMode;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\DSL\SchemaCatalog;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Registry\RegisteredSchemas;
use Warrant\Schema\ConditionDefinition;
use Warrant\Schema\RuleTemplateDefinition;

/**
 * The expansion phase, between parsing and compiling: turns the rules an author
 * wrote into the rules alone that
 * {@see \Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax} holds for everything
 * downstream to read. It reads only a {@see SyntaxBindingsResolved}, so what it
 * expands has been validated as written and has its bindings' values in place.
 *
 * Parsing keeps the author's shorthand. Expansion spells it out:
 *
 *  - every ability block's header is applied to the clauses under it;
 *  - every {@see IncludeInvocationNode} is replaced by the rules its template
 *    expands to, where the include was written — position is meaning;
 *  - every derived condition a rule names is replaced by the expression its
 *    method answers with, wrapped in a {@see DerivedConditionNode}.
 *
 * Expansion is a pure function of the rule set and the schemas it names. A
 * template or derived condition method is called with the arguments as written —
 * a `@context` or `@column` reference arrives as the reference, not its value, and
 * can only be passed on — and answers with text or an expression about no row. It
 * reads no user, no row, no query and no check context, so a rule set is expanded
 * once and the result serves every check, filter, diagnosis and reachability
 * question asked of it.
 *
 * Names inside a `check(<predicate> for <schema>)` belong to that schema, so the
 * predicate is expanded against its vocabulary. A `can(<ability> for <schema>)`
 * is left as it is: the rules it reaches are resolved per user, and are expanded
 * when that schema's own guard is asked for them.
 *
 * A template may include another, and a derived condition may name another,
 * either of them itself with different arguments, so expansion recurses, bounded
 * by an {@see ExpansionTrail}.
 */
final class RuleSetExpander
{
    /**
     * @param SchemaCatalog $catalog Where the schema the rules are for is found,
     *   whose templates and derived conditions are called, and the schema a
     *   `check(... for <schema>)` names, to expand its predicate against.
     */
    public function __construct(private readonly SchemaCatalog $catalog = new RegisteredSchemas)
    {
    }

    /**
     * Expand $syntax into rules alone: ability blocks opened up, every include
     * replaced by what its template expands to, and every derived condition by
     * the expression it answers with. Answers a rule set of those rules, for the
     * one schema the rules are for: the schema every `for` header in $syntax
     * names, or for rules with no header, the schema it was validated in.
     */
    public function expand(SyntaxBindingsResolved $syntax): RuleSetNode
    {
        $schemaKey = $this->schemaKeyOf($syntax);
        $schema = $this->catalog->find($schemaKey) ?? throw new InvalidArgumentException(sprintf(
            'There is no schema [%s] to expand the rules against.',
            $schemaKey,
        ));

        $set = $syntax->isSchemaScoped()
            ? $syntax->forSchema($schemaKey)
            : new RuleSetNode($schemaKey, $syntax->ruleEntries());

        return new RuleSetNode($schemaKey, $this->expandRuleSet($set, $schema));
    }

    /**
     * The one schema the rules in $syntax are for.
     */
    private function schemaKeyOf(SyntaxBindingsResolved $syntax): string
    {
        if ($syntax->isSchemaScoped()) {
            $schemaKeys = $syntax->schemaKeys();

            return count($schemaKeys) === 1 ? $schemaKeys[0] : throw new InvalidArgumentException(sprintf(
                'Rules are expanded for one schema at a time; the text holds rules for [%s].',
                implode('], [', $schemaKeys),
            ));
        }

        return $syntax->input->schema ?? throw new InvalidArgumentException(
            'Rules with no `for` header are expanded against the schema they were validated in, and these were '
                .'validated in none.',
        );
    }

    /**
     * @return list<WarrantRuleNode>
     */
    private function expandRuleSet(RuleSetNode $set, SchemaVocabulary $schema): array
    {
        /* Outside an ability block nothing names abilities on an entry's behalf.
           The validator reports a generic one against the text; this rejects it
           in any rule set, built in code or not. */
        foreach ($set->entries as $entry) {
            $generic = match (true) {
                $entry instanceof WarrantRuleNode => $entry->hasGenericClause(),
                $entry instanceof IncludeInvocationNode => $entry->isGeneric(),
                default => false,
            };

            if ($generic) {
                throw new InvalidArgumentException(LanguageRuleMessages::unnamedOutsideBlock($set->schemaKey));
            }
        }

        return $this->expandEntries($set->entries, $schema, $set->schemaKey, ExpansionTrail::root());
    }

    /**
     * @param list<IRuleEntryNode> $entries
     * @return list<WarrantRuleNode>
     */
    private function expandEntries(
        array $entries,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array
    {
        $expanded = [];

        foreach ($entries as $entry) {
            $rules = match (true) {
                $entry instanceof AbilityBlockNode => $this->expandAbilityBlock($entry, $schema, $schemaKey, $trail),
                $entry instanceof IncludeInvocationNode => $this->expandInclude($entry, $schema, $schemaKey, $trail),
                default => [$this->expandRule($entry, $schema, $schemaKey, $trail)],
            };

            foreach ($rules as $rule) {
                $expanded[] = $rule;
            }
        }

        return $expanded;
    }

    /**
     * The rules an ability block stands for: its header applied to every generic
     * entry in its body, and those entries expanded in turn.
     *
     * A block is grouping and nothing more, so it spends nothing of the trail.
     *
     * @return list<WarrantRuleNode>
     */
    private function expandAbilityBlock(
        AbilityBlockNode $block,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array {
        /* The header names the abilities, and nothing in the body may. The
           validator reports these against the text; this rejects them in any
           block, built in code or not. */
        if ($block->abilities === []) {
            throw new InvalidArgumentException(LanguageRuleMessages::blockNamesNone());
        }

        foreach ($block->entries as $entry) {
            match (true) {
                $entry instanceof AbilityBlockNode => throw new InvalidArgumentException(LanguageRuleMessages::blockInsideBlock()),
                $entry instanceof WarrantRuleNode && ! $entry->isGeneric()
                    => throw new InvalidArgumentException(LanguageRuleMessages::namedInsideBlock('A clause')),
                $entry instanceof IncludeInvocationNode && ! $entry->isGeneric()
                    => throw new InvalidArgumentException(LanguageRuleMessages::namedInsideBlock('An @include')),
                default => null,
            };
        }

        $entries = array_map(
            static fn (WarrantRuleNode|IncludeInvocationNode $entry): WarrantRuleNode|IncludeInvocationNode
                => $entry->withAbilities($block->abilities),
            $block->entries,
        );

        return $this->expandEntries($entries, $schema, $schemaKey, $trail);
    }

    /**
     * @return list<WarrantRuleNode>
     */
    private function expandInclude(
        IncludeInvocationNode $include,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array {
        $deeper = $trail->enteringInclude($include);

        $definition = self::resolveTemplate($schema, $schemaKey, $include);

        $generic = $this->templateEntries(
            $schema->{$definition->methodName}(...$include->arguments),
            $schema,
            $schemaKey,
            $definition,
        );

        /* The body is generic, as an ability block's is: the include names the
           abilities its clauses take, so they are applied here. */
        $entries = array_map(
            static fn (WarrantRuleNode|IncludeInvocationNode $entry): WarrantRuleNode|IncludeInvocationNode
                => $entry->withAbilities($include->abilities),
            $generic,
        );

        /* The body may hold includes of its own, taking the same abilities. They
           are expanded here rather than left for the caller so that what comes
           back is rules and only rules, however deep the templates went. */
        return $this->expandEntries($entries, $schema, $schemaKey, $deeper);
    }

    /**
     * The generic rules and includes in what a rule template answered with, in
     * whichever form it built them: rule text as a string or {@see WarrantSyntax},
     * a generic {@see WarrantRuleNode} or {@see IncludeInvocationNode}, or an
     * iterable of any of these.
     *
     * Every entry must be generic. The `@include` expanding the template names the
     * abilities its clauses take, so an entry naming its own has two answers to the
     * one question, and a rule set or ability block, which always name theirs, has
     * no place in a template at all.
     *
     * A string is parsed as any rule text is; rule text with bindings arrives
     * already parsed, as a {@see WarrantSyntax}.
     *
     * Every answer is validated before it is spliced in: rule text as the text it
     * is, so an error points into it, and a rule or include built in code as the
     * node it is.
     *
     * @param bool $validate false for the entries taken out of rule text that has
     *   already been validated as a whole
     * @return list<WarrantRuleNode|IncludeInvocationNode>
     */
    private function templateEntries(
        mixed $body,
        SchemaVocabulary $schema,
        string $schemaKey,
        RuleTemplateDefinition $definition,
        bool $validate = true,
    ): array {
        $answeredBy = sprintf('Rule template [%s::%s]', $schema::class, $definition->methodName);

        if (is_string($body)) {
            $body = WarrantSyntax::parse($body);
        }

        if ($body instanceof WarrantSyntax) {
            if (! $body->isEmpty() && ! $body->isRuleEntries()) {
                throw new RuntimeException(sprintf(
                    'Rule template [%s::%s] answered with rule text that is not unscoped rules and includes; '
                        .'a template answers with rules and includes that name no abilities.',
                    $schema::class,
                    $definition->methodName,
                ));
            }

            $valid = self::validateAnswer(
                $answeredBy,
                fn () => ValidSyntax::of($body, new ValidationInput($this->catalog, $schemaKey, GenericMode::RequireGeneric)),
            );

            /* Taking the entries out puts the values of the text's bindings in
               place, now that the text has been validated as written. */
            return $this->templateEntries($valid->ruleEntries(), $schema, $schemaKey, $definition, false);
        }

        if ($body instanceof WarrantRuleNode || $body instanceof IncludeInvocationNode) {
            if (! $body->isGeneric()) {
                throw new RuntimeException(sprintf(
                    'Rule template [%s::%s] answered with %s that names abilities; the @include that '
                        .'expands a template names the abilities its rules and includes take.',
                    $schema::class,
                    $definition->methodName,
                    $body instanceof WarrantRuleNode ? 'a rule' : 'an @include',
                ));
            }

            if ($validate) {
                self::validateAnswer($answeredBy, fn () => ValidSyntax::of(
                    new WarrantSyntax([$body]),
                    new ValidationInput($this->catalog, $schemaKey, GenericMode::RequireGeneric),
                ));
            }

            return [$body];
        }

        if ($body instanceof AbilityBlockNode || $body instanceof RuleSetNode) {
            throw new RuntimeException(sprintf(
                'Rule template [%s::%s] answered with %s, which names abilities; the @include that expands '
                    .'a template names the abilities its rules and includes take.',
                $schema::class,
                $definition->methodName,
                $body instanceof AbilityBlockNode ? 'an ability block' : 'a rule set',
            ));
        }

        if (is_iterable($body)) {
            $entries = [];

            foreach ($body as $item) {
                array_push($entries, ...$this->templateEntries($item, $schema, $schemaKey, $definition, $validate));
            }

            return $entries;
        }

        throw new RuntimeException(sprintf(
            'Rule template [%s::%s] must answer with rule text, a %s, a rule or @include that names no '
                .'abilities, or an iterable of them; got %s.',
            $schema::class,
            $definition->methodName,
            WarrantSyntax::class,
            get_debug_type($body),
        ));
    }

    /**
     * $rule with the derived conditions in its condition expanded. A rule with
     * nothing to expand comes back as the same instance.
     */
    private function expandRule(
        WarrantRuleNode $rule,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): WarrantRuleNode {
        /* A message after `they can` could never surface, and one that is not
           text cannot be shown. The validator reports these against the text;
           this rejects them in any rule, built in code or not. */
        foreach ($rule->canClauses as $clause) {
            if ($clause->message !== null) {
                throw new InvalidArgumentException(LanguageRuleMessages::messageOnCanClause());
            }
        }

        foreach ($rule->cannotClauses as $clause) {
            if ($clause->message !== null && ! is_string($clause->message) && ! $clause->message instanceof Closure) {
                throw new InvalidArgumentException(LanguageRuleMessages::messageIsNotText());
            }
        }

        if ($rule->conditions === null) {
            return $rule;
        }

        $conditions = $this->expandExpression($rule->conditions, $schema, $schemaKey, $trail);

        return $conditions === $rule->conditions
            ? $rule
            : new WarrantRuleNode($conditions, $rule->canClauses, $rule->cannotClauses);
    }

    /**
     * $node with every derived condition in it expanded, the names read against
     * $schema. A subtree with nothing to expand comes back as the same instance.
     */
    private function expandExpression(
        IBooleanExpressionNode $node,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): IBooleanExpressionNode {
        if ($node instanceof AndNode || $node instanceof OrNode) {
            $left = $this->expandExpression($node->leftSide, $schema, $schemaKey, $trail);
            $right = $this->expandExpression($node->rightSide, $schema, $schemaKey, $trail);

            return $left === $node->leftSide && $right === $node->rightSide
                ? $node
                : new ($node::class)($left, $right);
        }

        if ($node instanceof NotNode) {
            $operand = $this->expandExpression($node->operand, $schema, $schemaKey, $trail);

            return $operand === $node->operand ? $node : new NotNode($operand);
        }

        if ($node instanceof CrossSchemaConditionNode) {
            return $this->expandCheckPredicate($node, $trail);
        }

        if ($node instanceof ConditionNode) {
            $definition = $schema->getConditionDefinition($node->conditionKey);

            /* A name the schema does not declare is left for validation and the
               compiler to report, in the words they use for any other. */
            return $definition !== null && $definition->isDerived()
                ? $this->expandDerivedCondition($node, $definition, $schema, $schemaKey, $trail)
                : $node;
        }

        return $node;
    }

    /**
     * A `check(...)` with its predicate expanded against the schema it names,
     * whose conditions those are.
     */
    private function expandCheckPredicate(CrossSchemaConditionNode $node, ExpansionTrail $trail): CrossSchemaConditionNode
    {
        /* A schema nobody registered has no vocabulary to expand against. Leaving
           the reference as it is lets the validator and the compiler reject it with
           the message they give for any unknown schema. */
        $target = $this->catalog->find($node->schemaKey);

        if ($target === null) {
            return $node;
        }

        $predicate = $this->expandExpression($node->predicate, $target, $node->schemaKey, $trail);

        return $predicate === $node->predicate ? $node : new CrossSchemaConditionNode(
            $node->schemaKey,
            $predicate,
            $node->isRowBound,
            $node->boundKey,
            $node->contextMap,
            $node->alias,
        );
    }

    /**
     * Call a derived condition with the arguments the rule gave it, and wrap the
     * expanded expression it answers with.
     */
    private function expandDerivedCondition(
        ConditionNode $node,
        ConditionDefinition $definition,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): DerivedConditionNode {
        $deeper = $trail->enteringDerivedCondition($schemaKey, $node->conditionKey);

        if (count($node->parameters) < $definition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] requires at least %d argument(s), but the rule supplied %d.',
                $node->conditionKey,
                $schema::class,
                $definition->requiredArgumentCount,
                count($node->parameters),
            ));
        }

        $answer = $schema->{$definition->methodName}(...$node->parameters);

        return new DerivedConditionNode(
            $node->conditionKey,
            $node->parameters,
            $this->expandExpression(
                $this->derivedExpression($answer, $node, $schema, $schemaKey, $definition),
                $schema,
                $schemaKey,
                $deeper,
            ),
        );
    }

    /**
     * What a derived condition answered with, as an expression node.
     *
     * Rule text, as a string or a {@see WarrantSyntax} parsed with bindings, must
     * hold a condition expression, bare or under a `for` header naming this
     * schema. A {@see WarrantConditionBuilder} is unwrapped to the tree it
     * composed. A bool decides the outcome outright, and null answers unknown.
     *
     * An expression is validated before it is spliced in: rule text as the text
     * it is, so an error points into it, and an expression built in code as the
     * nodes it is.
     *
     * @param ConditionNode $node The condition the rule wrote, with the arguments
     *   it passed in.
     */
    private function derivedExpression(
        mixed $answer,
        ConditionNode $node,
        SchemaVocabulary $schema,
        string $schemaKey,
        ConditionDefinition $definition,
    ): IBooleanExpressionNode {
        $conditionKey = $node->conditionKey;

        if (is_string($answer) || $answer instanceof WarrantSyntax) {
            return $this->derivedSyntaxExpression($answer, $schema, $schemaKey, $definition);
        }

        $expression = $this->derivedNodeExpression($answer, $conditionKey, $schema, $definition);

        self::validateAnswer(
            sprintf('Derived condition [%s::%s]', $schema::class, $definition->methodName),
            fn () => ValidSyntax::of(
                new WarrantSyntax([$expression]),
                new ValidationInput($this->catalog, $schemaKey, callerColumnRefs: array_values(array_filter(
                    $node->parameters,
                    static fn (mixed $parameter): bool => $parameter instanceof ColumnRef,
                ))),
            ),
        );

        return $expression;
    }

    /**
     * What a derived condition answered with, when it answered with anything but
     * rule text.
     */
    private function derivedNodeExpression(
        mixed $answer,
        string $conditionKey,
        SchemaVocabulary $schema,
        ConditionDefinition $definition,
    ): IBooleanExpressionNode {
        if ($answer instanceof WarrantConditionBuilder) {
            /* A builder with no terms is the structural twin of a condition that
               added no where clause: it would mean "match everything", which is
               almost always a forgotten branch rather than an intent. */
            return $answer->buildConditions() ?? throw new InvalidArgumentException(sprintf(
                'Condition [%s] on schema [%s] returned a condition builder with no terms, which would '
                    .'silently match every row; add at least one term, return true/false to decide '
                    .'the outcome outright, or return null to answer unknown.',
                $conditionKey,
                $schema::class,
            ));
        }

        return match (true) {
            $answer instanceof IBooleanExpressionNode => $answer,
            is_bool($answer) => new BooleanNode($answer),
            $answer === null => new UnknownNode,
            default => throw new RuntimeException(sprintf(
                'Derived condition [%s::%s] must answer with an expression, a %s, rule text, a %s, a bool '
                    .'or null; got %s.',
                $schema::class,
                $definition->methodName,
                WarrantConditionBuilder::class,
                WarrantSyntax::class,
                get_debug_type($answer),
            )),
        };
    }

    /**
     * The condition expression in the rule text a derived condition answered with.
     *
     * Text that does not parse, or parses to something other than an expression,
     * is the condition's mistake, but the parser can only point at a position in
     * the text. Naming the method that answered with it is what makes it findable.
     *
     * A `for` header scopes the names to the schema it names, and the expression
     * is expanded and compiled against this one, so a header naming another is
     * rejected rather than read against the wrong vocabulary.
     */
    private function derivedSyntaxExpression(
        string|WarrantSyntax $text,
        SchemaVocabulary $schema,
        string $schemaKey,
        ConditionDefinition $definition,
    ): IBooleanExpressionNode {
        try {
            $syntax = is_string($text) ? WarrantSyntax::parse($text) : $text;

            /* Its shape alone, asked of the tree as written: no value is put in
               place until the text has been validated. */
            if (! $syntax->isExpression() && ! $syntax->isSchemaCondition()) {
                throw new LogicException(sprintf('Expected a condition expression, but the source holds %s.', $syntax->describe()));
            }
        } catch (WarrantSyntaxException|LogicException $e) {
            throw new RuntimeException(sprintf(
                "Derived condition [%s::%s] answered with rule text that is not a condition expression:\n\n%s",
                $schema::class,
                $definition->methodName,
                $e->getMessage(),
            ), previous: $e);
        }

        if ($syntax->isSchemaCondition() && $syntax->schemaKeys() !== [$schemaKey]) {
            throw new RuntimeException(sprintf(
                'Derived condition [%s::%s] answered with a condition for schema [%s]; it is a condition of '
                    .'schema [%s].',
                $schema::class,
                $definition->methodName,
                $syntax->schemaKeys()[0],
                $schemaKey,
            ));
        }

        $valid = self::validateAnswer(
            sprintf('Derived condition [%s::%s]', $schema::class, $definition->methodName),
            fn () => ValidSyntax::of($syntax, new ValidationInput($this->catalog, $schemaKey, GenericMode::Either)),
        );

        return $valid->conditionExpression();
    }

    /**
     * Run $validate over what $answeredBy answered with, so a mistake in it is
     * reported as that method's, still pointing into the text it is in.
     *
     * @template T
     * @param Closure(): T $validate
     * @return T
     */
    private static function validateAnswer(string $answeredBy, Closure $validate): mixed
    {
        try {
            return $validate();
        } catch (InvalidArgumentException $e) {
            $reason = $e instanceof RuleValidationException ? $e->reason : $e->getMessage();

            throw new RuleValidationException(
                $e instanceof RuleValidationException ? $e->diagnosticCode : DiagnosticCode::InvalidRule,
                sprintf('%s answered with a mistake: %s', $answeredBy, $reason),
                $e instanceof RuleValidationException ? $e->position : null,
                $e,
                $e instanceof RuleValidationException ? $e->span : null,
            );
        }
    }

    /**
     * The template an include names, rejecting one the schema does not declare and
     * one the include gives too few arguments.
     */
    private static function resolveTemplate(
        SchemaVocabulary $schema,
        string $schemaKey,
        IncludeInvocationNode $include,
    ): RuleTemplateDefinition {
        $definition = $schema->getRuleTemplateDefinition($include->templateKey);

        if ($definition === null) {
            throw new RuleValidationException(
                DiagnosticCode::UnknownTemplate,
                LanguageRuleMessages::unknownTemplate($schemaKey, $include->templateKey),
            );
        }

        if (count($include->arguments) < $definition->requiredArgumentCount) {
            throw new RuleValidationException(
                DiagnosticCode::TooFewArguments,
                LanguageRuleMessages::tooFewTemplateArguments(
                    $include->templateKey,
                    $definition->requiredArgumentCount,
                    count($include->arguments),
                ),
            );
        }

        return $definition;
    }
}
