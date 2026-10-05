<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;

/**
 * One `@include` in a rule set: the rule template to expand, the arguments to
 * hand it, and the abilities its headless clauses take.
 *
 * Outside an ability block the include names its abilities with its own `for`
 * list. Inside a block, or inside a rule template's body, it is headless: its
 * {@see $abilities} are empty, because the block header or the outer `@include`
 * names them, and {@see withAbilities()} applies them. What is left for the
 * expansion is the template's body, which only the schema can answer with.
 *
 * An invocation is deliberately not part of a {@see WarrantRuleNode}. A rule holds
 * concrete abilities and a condition tree; an include holds neither until it is
 * expanded, so it rides beside the rules in a {@see RuleSetNode} instead
 * of inside one.
 */
final readonly class IncludeInvocationNode implements IRuleEntryNode
{
    /**
     * @param list<mixed> $arguments The template's DSL arguments, resolved as a
     *   condition's are: literals, bindings already substituted, and the symbolic
     *   `@context` / `@column` references, which stay unresolved until a compile.
     * @param list<string> $abilities The abilities the expanded clauses apply to,
     *   or empty on a headless include. `*` is permitted, as it is in any ability
     *   list.
     */
    public function __construct(
        public string $templateKey,
        public array $arguments = [],
        public array $abilities = [],
    ) {
    }

    public function isHeadless(): bool
    {
        return $this->abilities === [];
    }

    /**
     * A copy of this headless include taking $abilities: the include it means
     * once the enclosing block header or `@include` has named them.
     *
     * @param list<string> $abilities
     */
    public function withAbilities(array $abilities): self
    {
        if (! $this->isHeadless()) {
            throw new InvalidArgumentException(sprintf(
                'Only a headless @include takes abilities from outside; @include %s names its own.',
                $this->templateKey,
            ));
        }

        return new self($this->templateKey, $this->arguments, $abilities);
    }
}
