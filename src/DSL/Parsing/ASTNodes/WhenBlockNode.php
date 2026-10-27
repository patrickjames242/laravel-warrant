<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A `when <condition> { ... }` block, with an optional `otherwise { ... }`: a
 * condition said once, over a body of entries it applies to.
 *
 * The block is sugar. Expansion ANDs the condition onto every rule the body
 * expands to, and `not` the condition onto every rule the `otherwise` body
 * expands to, so nothing after expansion knows the block was there. An unknown
 * condition leaves both bodies without effect, since `not` of an unknown is
 * unknown.
 *
 * The node holds a block as it was written, even an empty body; the validator
 * reports that against the text.
 */
final readonly class WhenBlockNode implements IRuleEntryNode
{
    /**
     * @param IBooleanExpressionNode $condition What the body's rules require.
     * @param list<IRuleEntryNode> $entries The body, in source order.
     * @param list<IRuleEntryNode>|null $otherwiseEntries The `otherwise` body, in
     *   source order, or null when the block has no `otherwise`.
     */
    public function __construct(
        public IBooleanExpressionNode $condition,
        public array $entries,
        public ?array $otherwiseEntries,
        public SyntaxLocation $location,
    ) {
        foreach ([...$entries, ...$otherwiseEntries ?? []] as $entry) {
            if (! $entry instanceof IRuleEntryNode) {
                throw new InvalidArgumentException(sprintf(
                    'A when block holds rules, includes and blocks, got %s.',
                    get_debug_type($entry),
                ));
            }
        }
    }

    /**
     * A copy of this block whose bodies take $abilities: the block it means once
     * the enclosing ability block header or `@include` has named them. Every
     * rule and include in the bodies must be generic, at any depth.
     *
     * @param list<NameNode|EveryAbilityExceptNode> $abilities
     */
    public function withAbilities(array $abilities): self
    {
        $applied = static fn (array $entries): array => array_map(
            static fn (IRuleEntryNode $entry): IRuleEntryNode => $entry instanceof AbilityBlockNode
                ? throw new InvalidArgumentException('An ability block inside a when block takes no abilities from outside; it names its own.')
                : $entry->withAbilities($abilities),
            $entries,
        );

        return new self(
            $this->condition,
            $applied($this->entries),
            $this->otherwiseEntries === null ? null : $applied($this->otherwiseEntries),
            $this->location,
        );
    }

    /**
     * The entries inside this block, in both bodies, and inside the when blocks
     * in them at any depth: what rules about abilities see, since a when block
     * is transparent to them.
     *
     * @return list<IRuleEntryNode>
     */
    public function entriesInside(): array
    {
        $inside = [];

        foreach ([...$this->entries, ...$this->otherwiseEntries ?? []] as $entry) {
            if ($entry instanceof self) {
                array_push($inside, ...$entry->entriesInside());
            } else {
                $inside[] = $entry;
            }
        }

        return $inside;
    }

    public function children(): array
    {
        return [
            $this->condition,
            ...$this->entries,
            ...$this->otherwiseEntries ?? [],
        ];
    }
}
