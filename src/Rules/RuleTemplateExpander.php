<?php

namespace Warrant\Rules;

use InvalidArgumentException;
use RuntimeException;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\SchemaVocabulary;
use Warrant\Schema\RuleTemplateDefinition;

/**
 * Replaces every {@see IncludeInvocation} in a rule set with the rules its
 * template expands to.
 *
 * Expansion is a pure function of the rule set and the schema: it calls a
 * template method, parses the text it answers with against the invocation's
 * abilities, and puts the resulting rules where the include was written. It reads
 * no user, no row, no query and no context, which is why it belongs to no one
 * caller — the compiler, reachability analysis and denial diagnosis all need it,
 * and all read a rule set the resolver produced rather than one another's output.
 *
 * Position is preserved because it is meaning: the rules a template expands to
 * sit exactly where the `@include` was, not appended after the rules that
 * followed it.
 *
 * A template may include another, including itself with different arguments, so
 * expansion recurses. What bounds that recursion is the caller's to decide, and is
 * the only thing that differs between callers: a compile hands over a trail that
 * enters a frame on its own call stack, so a runaway reports the ability hops that
 * led there; everything else takes the plain {@see DepthTrail}. See
 * {@see IncludeTrail}.
 */
final class RuleTemplateExpander
{
    /**
     * Return a copy of $set holding rules alone, every include replaced by what
     * its template expands to.
     *
     * $trail decides how deep the expansion may go and what a runaway reports;
     * omitting it takes the plain depth bound, which is what a caller outside a
     * compile wants.
     */
    public function expand(WarrantRuleSet $set, SchemaVocabulary $schema, ?IncludeTrail $trail = null): WarrantRuleSet
    {
        return new WarrantRuleSet($set->schemaKey, $this->expandEntries(
            $set->rules,
            $schema,
            $set->schemaKey,
            $trail ?? DepthTrail::root(),
        ));
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
     *
     * Nothing else about a template can be settled this way. Reading a body means
     * calling the method with concrete argument values, and an argument may be a
     * `@context` reference whose value arrives per check, so what is inside a body
     * is only ever known at an expansion.
     */
    public static function resolveTemplate(
        SchemaVocabulary $schema,
        string $schemaKey,
        IncludeInvocation $include,
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

    /**
     * @param list<RuleSetEntry> $entries
     * @return list<WarrantRule>
     */
    private function expandEntries(
        array $entries,
        SchemaVocabulary $schema,
        string $schemaKey,
        IncludeTrail $trail,
    ): array
    {
        $expanded = [];

        foreach ($entries as $entry) {
            if (! $entry instanceof IncludeInvocation) {
                $expanded[] = $entry;

                continue;
            }

            foreach ($this->expandOne($entry, $schema, $schemaKey, $trail) as $rule) {
                $expanded[] = $rule;
            }
        }

        return $expanded;
    }

    /**
     * @return list<WarrantRule>
     */
    private function expandOne(
        IncludeInvocation $include,
        SchemaVocabulary $schema,
        string $schemaKey,
        IncludeTrail $trail,
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

        $entries = WarrantParser::parseTemplateBody(
            is_string($body) ? $body : $body->syntax,
            $include->abilities,
            is_string($body) ? [] : $body->bindings,
        );

        /* The body may hold includes of its own, taking the same abilities. They
           are expanded here rather than left for the caller so that what comes
           back is rules and only rules, however deep the templates went. */
        return $this->expandEntries($entries, $schema, $schemaKey, $deeper);
    }
}
