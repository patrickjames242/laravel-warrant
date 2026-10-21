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

    public function children(): array
    {
        return [
            $this->condition,
            ...$this->entries,
            ...$this->otherwiseEntries ?? [],
        ];
    }
}
