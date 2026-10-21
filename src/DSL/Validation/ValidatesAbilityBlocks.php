<?php

namespace Warrant\DSL\Validation;

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\SchemaVocabulary;

/**
 * How a {@see SyntaxValidator} checks an ability block: its header names
 * abilities the schema declares, and nothing in its body names any or opens an
 * ability block of its own, looking through the when blocks in it.
 *
 * @internal
 */
trait ValidatesAbilityBlocks
{
    /**
     * An ability block names at least one ability in its header, and the rules
     * and includes in its body name none: the header names them all. A block
     * inside it is reported and not read further, having no header of its own
     * to stand under.
     */
    private function validateAbilityBlock(AbilityBlockNode $block, ?SchemaVocabulary $schema, ?string $schemaKey): void
    {
        if ($block->abilities === []) {
            $this->fail(DiagnosticCode::AbilityBlockNamesNone, LanguageRuleMessages::blockNamesNone(), $block->location);
        }

        $this->assertAbilitiesDeclared($block->abilities, $schema);
        $this->assertAbilityListsWellWritten($block);
        $this->validateAbilityBlockEntries($block->entries, $schema, $schemaKey);
    }

    /**
     * The entries of an ability block's body, and of the when blocks in it,
     * which name no abilities and open no ability block of their own.
     *
     * @param list<IRuleEntryNode> $entries
     */
    private function validateAbilityBlockEntries(array $entries, ?SchemaVocabulary $schema, ?string $schemaKey): void
    {
        foreach ($entries as $entry) {
            if ($entry instanceof WhenBlockNode) {
                $this->validateWhenBlock($entry, $schema, $schemaKey);
                $this->validateAbilityBlockEntries([...$entry->entries, ...$entry->otherwiseEntries ?? []], $schema, $schemaKey);
            } elseif ($entry instanceof AbilityBlockNode) {
                $this->fail(DiagnosticCode::AbilityBlockInsideBlock, LanguageRuleMessages::blockInsideBlock(), $entry->location);
            } elseif ($entry instanceof IncludeInvocationNode) {
                if (! $entry->isGeneric()) {
                    $this->fail(
                        DiagnosticCode::AbilitiesNamedInsideBlock,
                        LanguageRuleMessages::namedInsideBlock('An @include'),
                        $entry->abilities[0]->location,
                    );
                }

                $this->assertTemplateDeclared($entry, $schema, $schemaKey);
            } elseif ($entry instanceof WarrantRuleNode) {
                foreach ([...$entry->canClauses, ...$entry->cannotClauses] as $clause) {
                    if ($clause->abilities !== []) {
                        $this->fail(
                            DiagnosticCode::AbilitiesNamedInsideBlock,
                            LanguageRuleMessages::namedInsideBlock('A clause'),
                            $clause->abilities[0]->location,
                        );
                    }
                }

                $this->validateRule($entry, $schema, $schemaKey);
            }
        }
    }
}
