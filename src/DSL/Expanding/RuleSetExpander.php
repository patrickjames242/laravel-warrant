<?php

namespace Warrant\DSL\Expanding;

use InvalidArgumentException;
use RuntimeException;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Rules\WarrantRuleTemplate;
use Warrant\Schema\RuleTemplateDefinition;

/**
 * The expansion phase, between parsing and compiling: turns the rule set an
 * author wrote into the {@see ExpandedRuleSet} everything downstream reads.
 *
 * Parsing keeps the author's shorthand. Expansion spells it out: every ability
 * block's header is applied to the clauses under it, and every
 * {@see IncludeInvocationNode} is replaced by the rules its template expands to.
 *
 * Expansion is a pure function of the rule set and the schema: it calls a
 * template method with the include's arguments as written — a `@context` or
 * `@column` reference arrives as the reference, not its value — parses the text it
 * answers with, and puts the resulting rules where the include was written. It
 * reads no user, no row, no query and no check context, so a rule set is expanded
 * once and the result serves every check, filter, diagnosis and reachability
 * question asked of it.
 *
 * Position is preserved because it is meaning: the rules a template expands to
 * sit exactly where the `@include` was, not appended after the rules that
 * followed it.
 *
 * A template may include another, including itself with different arguments, so
 * expansion recurses, bounded by an {@see ExpansionTrail}. Nothing about who asked
 * for the expansion changes that bound or what a runaway reports: the phase stands
 * on its own, and the include chain is the whole story.
 */
final class RuleSetExpander
{
    /**
     * Expand $set into rules alone: ability blocks opened up, and every include
     * replaced by what its template expands to.
     */
    public function expand(RuleSetNode $set, SchemaVocabulary $schema): ExpandedRuleSet
    {
        return new ExpandedRuleSet($set->schemaKey, $this->expandEntries(
            $set->flatEntries(),
            $schema,
            $set->schemaKey,
            ExpansionTrail::root(),
        ));
    }

    /**
     * @param list<WarrantRuleNode|IncludeInvocationNode> $entries
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
            if (! $entry instanceof IncludeInvocationNode) {
                $expanded[] = $entry;

                continue;
            }

            foreach ($this->expandInclude($entry, $schema, $schemaKey, $trail) as $rule) {
                $expanded[] = $rule;
            }
        }

        return $expanded;
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
        $deeper = $trail->entering($include);

        $definition = self::resolveTemplate($schema, $schemaKey, $include);

        $body = $schema->{$definition->methodName}(...$include->arguments);

        if (! is_string($body) && ! $body instanceof WarrantRuleTemplate) {
            throw new RuntimeException(sprintf(
                'Rule template [%s::%s] must answer with a string or a %s, got %s.',
                $schema::class,
                $definition->methodName,
                WarrantRuleTemplate::class,
                get_debug_type($body),
            ));
        }

        $headless = WarrantParser::parseTemplateBody(
            is_string($body) ? $body : $body->syntax,
            is_string($body) ? [] : $body->bindings,
        );

        /* The body is headless, as an ability block's is: the include names the
           abilities its clauses take, so they are applied here. */
        $entries = array_map(
            static fn (WarrantRuleNode|IncludeInvocationNode $entry): WarrantRuleNode|IncludeInvocationNode
                => $entry->withAbilities($include->abilities),
            $headless,
        );

        /* The body may hold includes of its own, taking the same abilities. They
           are expanded here rather than left for the caller so that what comes
           back is rules and only rules, however deep the templates went. */
        return $this->expandEntries($entries, $schema, $schemaKey, $deeper);
    }

    /**
     * The template an include names, rejecting one the schema does not declare and
     * one the include gives too few arguments.
     *
     * Static, and reached from outside, because
     * {@see \Warrant\DSL\Parsing\Validation\RuleSetValidator} makes exactly these
     * two checks over rule text before anything is expanded, and the two have to
     * agree — the same rejection, in the same words. Sharing one implementation is
     * what makes that true rather than intended.
     */
    public static function resolveTemplate(
        SchemaVocabulary $schema,
        string $schemaKey,
        IncludeInvocationNode $include,
    ): RuleTemplateDefinition {
        $definition = $schema->getRuleTemplateDefinition($include->templateKey);

        if ($definition === null) {
            throw new InvalidArgumentException(sprintf(
                'Schema [%s] declares no rule template [%s], named by an @include.',
                $schemaKey,
                $include->templateKey,
            ));
        }

        if (count($include->arguments) < $definition->requiredArgumentCount) {
            throw new InvalidArgumentException(sprintf(
                'Rule template [%s] requires %d argument(s), but the @include supplies %d.',
                $include->templateKey,
                $definition->requiredArgumentCount,
                count($include->arguments),
            ));
        }

        return $definition;
    }
}
