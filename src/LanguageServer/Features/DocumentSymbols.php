<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Positions\SourceMap;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Workspace;

/**
 * The outline of a document's rule text: each `for` block, ability block, rule
 * and `@include`, nested as they are written.
 */
final class DocumentSymbols
{
    private const KIND_MODULE = 2;
    private const KIND_NAMESPACE = 3;
    private const KIND_FUNCTION = 12;
    private const KIND_BOOLEAN = 17;
    private const KIND_STRUCT = 23;

    /**
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @return list<array<string, mixed>> LSP `DocumentSymbol` objects.
     */
    public static function of(Workspace $workspace, Document $document): array
    {
        $symbols = [];

        foreach ($workspace->analyze($document) as $analyzed) {
            foreach ($analyzed->analysis->syntax->children as $child) {
                $symbol = self::symbolOf($child, $analyzed, $document);

                if ($symbol !== null) {
                    $symbols[] = $symbol;
                }
            }
        }

        return $symbols;
    }

    /**
     * The symbol for $node, or null when it has no place in the text.
     *
     * @param object $node A node of the tree.
     * @param AnalyzedFragment $analyzed The fragment it was read from.
     * @param Document $document The document the fragment is in.
     * @return array<string, mixed>|null
     */
    private static function symbolOf(object $node, AnalyzedFragment $analyzed, Document $document): ?array
    {
        $positions = $analyzed->analysis->syntax->positions ?? new SourceMap;
        $span = $positions->spanOf($node);

        if ($span === null) {
            return null;
        }

        [$name, $kind, $children] = match (true) {
            $node instanceof RuleSetNode => ['for '.self::orMissing($node->schemaKey), self::KIND_NAMESPACE, $node->entries],
            $node instanceof SchemaConditionNode => ['for '.self::orMissing($node->schemaKey), self::KIND_NAMESPACE, []],
            $node instanceof AbilityBlockNode => ['can they '.implode(', ', $node->abilities), self::KIND_STRUCT, $node->entries],
            $node instanceof IncludeInvocationNode => ['@include '.self::orMissing($node->templateKey), self::KIND_MODULE, []],
            $node instanceof WarrantRuleNode => [self::ruleName($node), self::KIND_FUNCTION, []],
            $node instanceof IBooleanExpressionNode => ['condition', self::KIND_BOOLEAN, []],
            default => [null, null, []],
        };

        if ($name === null) {
            return null;
        }

        $fragment = $analyzed->fragment;
        $range = $document->rangeOf($fragment->fileOffsetOf($span->offset), $fragment->fileOffsetOf($span->endOffset, isEnd: true));
        $symbol = [
            'name' => $name,
            'kind' => $kind,
            'range' => $range,
            'selectionRange' => $range,
        ];

        if ($node instanceof WarrantRuleNode) {
            $symbol['detail'] = self::firstLine(substr($fragment->text, $span->offset, $span->length()));
        }

        $childSymbols = array_values(array_filter(array_map(
            static fn (object $child): ?array => self::symbolOf($child, $analyzed, $document),
            $children,
        )));

        if ($childSymbols !== []) {
            $symbol['children'] = $childSymbols;
        }

        return $symbol;
    }

    /**
     * What a rule grants and denies: `can view, edit · cannot delete`.
     *
     * @param WarrantRuleNode $rule The rule.
     */
    private static function ruleName(WarrantRuleNode $rule): string
    {
        $parts = [];

        if ($rule->canClauses !== []) {
            $parts[] = 'can '.(implode(', ', $rule->canAbilities()) ?: '…');
        }

        if ($rule->cannotClauses !== []) {
            $parts[] = 'cannot '.(implode(', ', $rule->cannotAbilities()) ?: '…');
        }

        return $parts === [] ? 'rule' : implode(' · ', $parts);
    }

    /**
     * @param string $name A name from the tree, which is empty where the text is missing it.
     */
    private static function orMissing(string $name): string
    {
        return $name === '' ? '…' : $name;
    }

    /**
     * @param string $text Rule text.
     */
    private static function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');

        return strlen($line) > 80 ? substr($line, 0, 77).'...' : $line;
    }
}
