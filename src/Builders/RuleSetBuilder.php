<?php

namespace Warrant\Builders;

use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\SyntaxSource;

/**
 * The `$rule` a {@see Build::ruleSet()} callback receives. Calling it, `$rule()`,
 * appends a new {@see WarrantRuleBuilder}; `$rule->include(...)` appends an
 * {@see IncludeInvocationBuilder}. The entries keep the order they were written
 * in, since an include's position in a rule set matters.
 */
final class RuleSetBuilder
{
    /** @var list<WarrantRuleBuilder|IncludeInvocationBuilder> */
    private array $entries = [];

    public function __invoke(): WarrantRuleBuilder
    {
        return $this->entries[] = new WarrantRuleBuilder;
    }

    /**
     * @param array<int, mixed> $arguments The template's arguments: values, or
     *   {@see Ref}s.
     */
    public function include(string $template, array $arguments = []): IncludeInvocationBuilder
    {
        return $this->entries[] = new IncludeInvocationBuilder($template, $arguments);
    }

    /**
     * Every entry, in the order written, placed in $source.
     *
     * @return list<IRuleEntryNode>
     */
    public function entries(SyntaxSource $source): array
    {
        return array_map(
            fn (WarrantRuleBuilder|IncludeInvocationBuilder $entry): IRuleEntryNode => $entry instanceof WarrantRuleBuilder
                ? $entry->toRule($source)
                : $entry->toNode($source),
            $this->entries,
        );
    }
}
