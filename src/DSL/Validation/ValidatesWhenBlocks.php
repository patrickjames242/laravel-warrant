<?php

namespace Warrant\DSL\Validation;

use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\Schema\Vocabulary;

/**
 * How a {@see SyntaxValidator} checks a when block of its own: its condition,
 * and that neither body is empty. The entries in its bodies are checked where
 * they stand, as the entries around the block are.
 *
 * @internal
 */
trait ValidatesWhenBlocks
{
    /**
     * A when block's condition is a condition like a rule's, and neither of its
     * bodies is empty: a body with nothing in it guards nothing, and an empty
     * `otherwise` is one to leave off.
     */
    private function validateWhenBlock(WhenBlockNode $block, ?Vocabulary $schema, ?string $schemaKey): void
    {
        $this->validateCondition($block->condition, $schema, $schemaKey);

        if ($block->entries === []) {
            $this->fail(DiagnosticCode::WhenBlockEmpty, LanguageRuleMessages::whenBlockEmpty(), $block->location);
        }

        if ($block->otherwiseEntries === []) {
            $this->fail(DiagnosticCode::WhenBlockEmpty, LanguageRuleMessages::otherwiseEmpty(), $block->location);
        }
    }
}
