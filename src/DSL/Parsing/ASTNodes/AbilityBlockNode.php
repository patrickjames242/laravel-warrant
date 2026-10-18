<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * A `can they <ability>, ... { ... }` block: abilities said once, over a body of
 * generic clauses.
 *
 * The header is the only place the abilities live. Every entry in the body is
 * generic — its clauses and includes name no abilities — and expansion applies
 * the header to each one ({@see \Warrant\DSL\Expanding\RuleSetExpander}).
 *
 * The node holds a block as it was written, even one that breaks that: a header
 * naming nothing, an entry naming its own abilities, or a block inside it. The
 * validator reports those against the text, and the expander rejects them.
 */
final readonly class AbilityBlockNode implements IRuleEntryNode
{
    /* The names this node's parts are placed under in its {@see SyntaxLocation}:
       each is the property the part is stored in. */
    public const PART_ABILITIES = 'abilities';

    /**
     * @param list<string> $abilities The abilities every clause in the body takes.
     * @param list<IRuleEntryNode> $entries The body, in source order.
     */
    public function __construct(
        public array $abilities,
        public array $entries,
        public SyntaxLocation $location,
    ) {
        foreach ($entries as $entry) {
            if (! $entry instanceof IRuleEntryNode) {
                throw new InvalidArgumentException(sprintf(
                    'An ability block holds rules, includes and ability blocks, got %s.',
                    get_debug_type($entry),
                ));
            }
        }
    }
}
