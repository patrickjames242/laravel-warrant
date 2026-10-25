<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\Node;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Workspace;

/**
 * The outline of a document's rule text: each `for` block, ability block, when
 * block, rule and `@include`, nested as they are written. A when block's
 * children are the entries of both its bodies.
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
     * @param Node $node A node of the tree.
     * @param AnalyzedFragment $analyzed The fragment it was read from.
     * @param Document $document The document the fragment is in.
     * @return array<string, mixed>|null
     */
    private static function symbolOf(Node $node, AnalyzedFragment $analyzed, Document $document): ?array
    {
        $span = $node->location->span;

        if ($span === null) {
            return null;
        }

        [$name, $kind, $children] = match (true) {
            $node instanceof RuleSetNode => ['for '.self::orMissing($node->schemaKey()), self::KIND_NAMESPACE, $node->entries],
            $node instanceof SchemaConditionNode => ['for '.self::orMissing($node->schemaKey()), self::KIND_NAMESPACE, []],
            $node instanceof AbilityBlockNode => [
                'can they '.self::abilities($node->abilities),
                self::KIND_STRUCT,
                $node->entries,
            ],
            $node instanceof WhenBlockNode => [
                'when '.self::writtenCondition($node, $analyzed),
                self::KIND_STRUCT,
                [...$node->entries, ...$node->otherwiseEntries ?? []],
            ],
            $node instanceof IncludeInvocationNode => ['@include '.self::orMissing($node->templateKey->name), self::KIND_MODULE, []],
            $node instanceof RuleNode => [self::ruleName($node), self::KIND_FUNCTION, []],
            $node instanceof BooleanExpressionNode => ['condition', self::KIND_BOOLEAN, []],
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

        if ($node instanceof RuleNode) {
            $symbol['detail'] = self::firstLine(substr($fragment->text, $span->offset, $span->length()));
        }

        $childSymbols = array_values(array_filter(array_map(
            static fn (Node $child): ?array => self::symbolOf($child, $analyzed, $document),
            $children,
        )));

        if ($childSymbols !== []) {
            $symbol['children'] = $childSymbols;
        }

        return $symbol;
    }

    /**
     * A when block's condition as written, on one line, or `…` when it was not
     * written.
     */
    private static function writtenCondition(WhenBlockNode $block, AnalyzedFragment $analyzed): string
    {
        $span = $block->condition->location->span;

        return $span === null || $span->length() === 0
            ? '…'
            : self::firstLine(substr($analyzed->fragment->text, $span->offset, $span->length()));
    }

    /**
     * What a rule grants and denies: `can view, edit · cannot delete`.
     *
     * @param RuleNode $rule The rule.
     */
    private static function ruleName(RuleNode $rule): string
    {
        $parts = [];

        $abilities = static fn (array $clauses): string => self::abilities(array_merge(
            [],
            ...array_map(static fn (CanClauseNode|CannotClauseNode $clause): array => $clause->abilities, $clauses),
        )) ?: '…';

        if ($rule->canClauses !== []) {
            $parts[] = 'can '.$abilities($rule->canClauses);
        }

        if ($rule->cannotClauses !== []) {
            $parts[] = 'cannot '.$abilities($rule->cannotClauses);
        }

        return $parts === [] ? 'rule' : implode(' · ', $parts);
    }

    /**
     * Abilities as the text writes them: each a name, `*`, or a binding.
     *
     * @param list<NameNode|BindingNode> $abilities
     */
    private static function abilities(array $abilities): string
    {
        return implode(', ', array_map(
            static fn (NameNode|BindingNode $ability): string => $ability instanceof BindingNode ? ':'.$ability->name : $ability->name,
            $abilities,
        ));
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
