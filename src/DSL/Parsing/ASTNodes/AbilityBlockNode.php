<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;

/**
 * A `can they <ability>, ... { ... }` block: abilities said once, over a body of
 * headless clauses.
 *
 * The header is the only place the abilities live. Every entry in the body is
 * headless — its clauses and includes name no abilities — exactly as the source
 * writes them, and expansion applies the header to each one
 * ({@see \Warrant\DSL\Expanding\RuleSetExpander}). The
 * constructor holds every entry to that, so the header is the complete account
 * of what the body grants and denies.
 */
final readonly class AbilityBlockNode implements IRuleEntryNode
{
    /**
     * @param list<string> $abilities The abilities every clause in the body takes.
     * @param list<WarrantRuleNode|IncludeInvocationNode> $entries The headless body,
     *   in source order.
     */
    public function __construct(
        public array $abilities,
        public array $entries = [],
    ) {
        if ($abilities === []) {
            throw new InvalidArgumentException('An ability block names at least one ability.');
        }

        foreach ($entries as $entry) {
            $headless = match (true) {
                $entry instanceof WarrantRuleNode => $entry->isHeadless(),
                $entry instanceof IncludeInvocationNode => $entry->isHeadless(),
                default => throw new InvalidArgumentException(sprintf(
                    'An ability block holds rules and includes, got %s; ability blocks do not nest.',
                    get_debug_type($entry),
                )),
            };

            if (! $headless) {
                throw new InvalidArgumentException(sprintf(
                    'A clause or @include inside a `can they %s` block may not name abilities; the block header '
                        .'already names them.',
                    implode(', ', $abilities),
                ));
            }
        }
    }
}
