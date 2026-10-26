<?php

namespace Warrant\DSL\Expanding;

use Closure;
use InvalidArgumentException;
use Warrant\DSL\Expanding\Concerns\BindsParametersByName;
use Warrant\DSL\Expanding\Concerns\ExpandsAbilityBlocks;
use Warrant\DSL\Expanding\Concerns\ExpandsCheckPredicates;
use Warrant\DSL\Expanding\Concerns\ExpandsDerivedConditions;
use Warrant\DSL\Expanding\Concerns\ExpandsExpressions;
use Warrant\DSL\Expanding\Concerns\ExpandsRuleTemplates;
use Warrant\DSL\Expanding\Concerns\ExpandsRules;
use Warrant\DSL\Expanding\Concerns\ExpandsWhenBlocks;
use Warrant\DSL\Expanding\Concerns\ReplacesBindings;
use Warrant\DSL\ISchemaCatalog;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\Facades\Warrant;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * The expansion phase, between parsing and compiling: turns the rules an author
 * wrote into the rules alone that
 * {@see \Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax} holds for everything
 * downstream to read. It reads only {@see ValidSyntax}es, so what it expands
 * has been validated as written, and it replaces every binding with its value,
 * so what it answers with holds none. It reads any number of them for one
 * schema, takes the rules each holds about that schema, and joins them in the
 * order given.
 *
 * Parsing keeps the author's shorthand. Expansion spells it out:
 *
 *  - every ability block's header is applied to the can/cannot clauses under it;
 *  - every when block's condition is put on the rules in its body, and its
 *    negation on the rules in its `otherwise`;
 *  - every {@see IncludeInvocationNode} is replaced by the rules its template
 *    expands to, where the include was written — position is meaning;
 *  - every derived condition a rule names is replaced by the expression its
 *    method answers with, wrapped in a {@see DerivedConditionNode}.
 *
 * Expansion is a pure function of the rule set and the schemas it names. A
 * template or derived condition method is called with the arguments as written,
 * a literal or a binding as its value — a `@context`, `@column` or `@sql`
 * reference arrives as the reference, not its value, and can only be passed on —
 * and answers with text or an expression about no row. What it answers with is
 * placed in a source of its own, whose producer is that template or condition,
 * naming the `@include` or condition that invoked it. A template may also be
 * handed a {@see \Warrant\Schema\RuleTemplates\RuleTemplateContext} naming the abilities its
 * include takes, which the rule set says too. It reads no user, no row, no query and no check context, so a rule set is expanded
 * once and the result serves every check, filter, diagnosis and reachability
 * question asked of it.
 *
 * Names inside a `check(<predicate> for <schema>)` belong to that schema, so the
 * predicate is expanded against that schema. A `can(<ability> for <schema>)`
 * is left as it is: the rules it reaches are resolved per user, and are expanded
 * when that schema's own guard is asked for them.
 *
 * A template may include another, and a derived condition may name another,
 * either of them itself with different arguments, so expansion recurses, bounded
 * by an {@see ExpansionTrail}.
 */
final class Expander
{
    use BindsParametersByName;
    use ExpandsAbilityBlocks;
    use ExpandsCheckPredicates;
    use ExpandsDerivedConditions;
    use ExpandsExpressions;
    use ExpandsRules;
    use ExpandsRuleTemplates;
    use ExpandsWhenBlocks;
    use ReplacesBindings;

    private readonly ISchemaCatalog $catalog;

    /**
     * @param ISchemaCatalog|null $catalog What names are looked up in, and what
     *   the rules a template or derived condition answers with are validated
     *   against; the application's registry when null. The schemas whose
     *   templates and derived conditions are called come from the registry,
     *   since calling one needs the schema itself and not its vocabulary.
     */
    public function __construct(?ISchemaCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? Warrant::registry();
    }

    /**
     * Expand the rules in $syntaxes that are about $schemaKey into rules alone,
     * in order: ability blocks and when blocks opened up, every include
     * replaced by what its template expands to, and every derived condition by
     * the expression it answers with. Rules about any other schema are left
     * out.
     *
     * @return list<RuleNode>
     */
    public function expand(string $schemaKey, ValidSyntax ...$syntaxes): array
    {
        $schema = $this->schemaNamed($schemaKey) ?? throw new InvalidArgumentException(sprintf(
            'There is no schema [%s] to expand the rules against.',
            $schemaKey,
        ));

        $entries = [];

        foreach ($syntaxes as $syntax) {
            array_push($entries, ...self::eachWithBindingsReplaced($syntax->entriesFor($schemaKey)));
        }

        return $this->expandTopLevelEntries($entries, $schema, $schemaKey);
    }

    /**
     * Expand the entries the syntaxes hold at the top, outside any ability
     * block or `@include`, where every entry names its own abilities, starting
     * a fresh expansion trail.
     *
     * @param list<IRuleEntryNode> $entries
     * @return list<RuleNode>
     */
    private function expandTopLevelEntries(array $entries, ISchema $schema, string $schemaKey): array
    {
        /* Outside an ability block nothing names abilities on an entry's behalf.
           The validator reports a generic one against the text; this rejects it
           in any rule set, built in code or not, looking through when blocks. */
        foreach ($entries as $written) {
            foreach ($written instanceof WhenBlockNode ? $written->entriesInside() : [$written] as $entry) {
                $generic = match (true) {
                    $entry instanceof RuleNode => $entry->hasGenericCanOrCannotClause(),
                    $entry instanceof IncludeInvocationNode => $entry->isGeneric(),
                    default => false,
                };

                if ($generic) {
                    throw new InvalidArgumentException(LanguageRuleMessages::unnamedOutsideBlock($schemaKey));
                }
            }
        }

        return $this->expandEntries($entries, $schema, $schemaKey, ExpansionTrail::root());
    }

    /**
     * Expand $entries at any depth — at the top, in an ability block's body, or
     * in what a template answered with — into rules, carrying $trail down.
     *
     * @param list<IRuleEntryNode> $entries
     * @return list<RuleNode>
     */
    private function expandEntries(
        array $entries,
        ISchema $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array
    {
        $expanded = [];

        foreach ($entries as $entry) {
            $rules = match (true) {
                $entry instanceof AbilityBlockNode => $this->expandAbilityBlock($entry, $schema, $schemaKey, $trail),
                $entry instanceof IncludeInvocationNode => $this->expandInclude($entry, $schema, $schemaKey, $trail),
                $entry instanceof WhenBlockNode => $this->expandWhenBlock($entry, $schema, $schemaKey, $trail),
                default => [$this->expandRule($entry, $schema, $schemaKey, $trail)],
            };

            foreach ($rules as $rule) {
                $expanded[] = $rule;
            }
        }

        return $expanded;
    }

    /**
     * Run $validate over what $producer answered with, so a mistake in it is
     * reported as that method's, still pointing into the text it is in.
     *
     * @template T
     * @param Closure(): T $validate
     * @return T
     */
    private static function validateAnswer(SyntaxProducer $producer, Closure $validate): mixed
    {
        try {
            return $validate();
        } catch (InvalidArgumentException $e) {
            $reason = $e instanceof RuleValidationException ? $e->reason : $e->getMessage();

            throw new RuleValidationException(
                $e instanceof RuleValidationException ? $e->diagnosticCode : DiagnosticCode::InvalidRule,
                sprintf('%s answered with a mistake: %s', $producer->describe(), $reason),
                $e instanceof RuleValidationException ? $e->position : null,
                $e,
                $e instanceof RuleValidationException ? $e->span : null,
            );
        }
    }

    /**
     * The schema registered under $schemaKey, or null when none is.
     */
    private function schemaNamed(string $schemaKey): ?ISchema
    {
        return Warrant::registry()->resolveOrNull($schemaKey)?->schema;
    }

    /**
     * The values a template or derived condition method is called with: a
     * literal as its value, and a `@context`, `@column` or `@sql` reference as
     * the reference itself. Bindings are replaced by their values by now.
     *
     * @param list<INode> $arguments
     * @return list<mixed>
     */
    private static function argumentValues(array $arguments): array
    {
        return array_map(
            static fn (INode $argument): mixed => $argument instanceof LiteralNode ? $argument->value : $argument,
            $arguments,
        );
    }

}
