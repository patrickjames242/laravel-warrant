<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A `can they <ability>, ... { ... }` block: abilities said once, over a body of
 * generic can/cannot clauses.
 *
 * The header is the only place the abilities live. Every entry in the body is
 * generic — its can/cannot clauses and includes name no abilities — and expansion applies
 * the header to each one ({@see \Warrant\DSL\Expanding\Expander}).
 *
 * The node holds a block as it was written, even one that breaks that: a header
 * naming nothing, an entry naming its own abilities, or a block inside it. The
 * validator reports those against the text, and the expander rejects them.
 */
final readonly class AbilityBlockNode implements RuleEntryNode
{
    /**
     * @param list<NameNode|BindingNode> $abilities The abilities every can/cannot clause in
     *   the body takes, and bindings whose values name them until expansion puts
     *   those names in their place.
     * @param list<RuleEntryNode> $entries The body, in source order.
     */
    public function __construct(
        public array $abilities,
        public array $entries,
        public SyntaxLocation $location,
    ) {
        foreach ($entries as $entry) {
            if (! $entry instanceof RuleEntryNode) {
                throw new InvalidArgumentException(sprintf(
                    'An ability block holds rules, includes and ability blocks, got %s.',
                    get_debug_type($entry),
                ));
            }
        }
    }

    public function children(): array
    {
        return [
            ...$this->abilities,
            ...$this->entries,
        ];
    }
}
