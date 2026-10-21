<?php

namespace Warrant\DSL\Expanding\Concerns;

use InvalidArgumentException;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Expanding\ExpansionTrail;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\SchemaVocabulary;
use Warrant\DSL\Validation\LanguageRuleMessages;

/**
 * How {@see Expander} expands an ability block: its header's abilities are
 * applied to every generic entry in its body, and those entries expanded in
 * turn.
 *
 * @internal
 */
trait ExpandsAbilityBlocks
{
    /**
     * The rules an ability block stands for: its header applied to every generic
     * entry in its body, and those entries expanded in turn.
     *
     * A block is grouping and nothing more, so it spends nothing of the trail.
     *
     * @return list<WarrantRuleNode>
     */
    private function expandAbilityBlock(
        AbilityBlockNode $block,
        SchemaVocabulary $schema,
        string $schemaKey,
        ExpansionTrail $trail,
    ): array {
        /* The header names the abilities, and nothing in the body may. The
           validator reports these against the text; this rejects them in any
           block, built in code or not, looking through the when blocks in it. */
        if ($block->abilities === []) {
            throw new InvalidArgumentException(LanguageRuleMessages::blockNamesNone());
        }

        foreach ($block->entries as $written) {
            foreach ($written instanceof WhenBlockNode ? $written->entriesInside() : [$written] as $entry) {
                match (true) {
                    $entry instanceof AbilityBlockNode => throw new InvalidArgumentException(LanguageRuleMessages::blockInsideBlock()),
                    $entry instanceof WarrantRuleNode && ! $entry->isGeneric()
                        => throw new InvalidArgumentException(LanguageRuleMessages::namedInsideBlock('A clause')),
                    $entry instanceof IncludeInvocationNode && ! $entry->isGeneric()
                        => throw new InvalidArgumentException(LanguageRuleMessages::namedInsideBlock('An @include')),
                    default => null,
                };
            }
        }

        $entries = array_map(
            static fn (WarrantRuleNode|IncludeInvocationNode|WhenBlockNode $entry): WarrantRuleNode|IncludeInvocationNode|WhenBlockNode
                => $entry->withAbilities($block->abilities),
            $block->entries,
        );

        return $this->expandEntries($entries, $schema, $schemaKey, $trail);
    }
}
