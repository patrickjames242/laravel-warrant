<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Positions\CursorContext;
use Warrant\LanguageServer\Workspace;

/**
 * The parameter names an editor shows before each argument of a condition or
 * an `@include` in rule text, as it shows them before the arguments of a PHP
 * call. A derived condition is called as any other condition is.
 *
 * Only a literal or an `@sql` reference gets a hint, since neither says what
 * it stands for. A binding, `@context` or `@column` argument is written by a
 * name of its own, which says it, as a PHP variable passed to a call does.
 */
final class InlayHints
{
    /** The LSP kind of a hint that names a parameter. */
    private const PARAMETER = 2;

    /**
     * The hints in $range of $document.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{start?: array{line?: int, character?: int}, end?: array{line?: int, character?: int}} $range
     *   The LSP range the editor shows.
     * @return list<array{position: array{line: int, character: int}, label: string, kind: int, paddingRight: bool}>
     */
    public static function in(Workspace $workspace, Document $document, array $range): array
    {
        $start = $document->offsetAt($range['start'] ?? []);
        $end = isset($range['end']) ? $document->offsetAt($range['end']) : strlen($document->text);
        $hints = [];

        foreach ($workspace->analyze($document) as $fragment) {
            foreach (self::callsIn($fragment) as [$callee, $arguments]) {
                foreach (self::parameterNames($workspace, $fragment, $callee) as $index => $name) {
                    $argument = $arguments[$index] ?? null;
                    $span = $argument?->location->span;

                    if (! ($argument instanceof LiteralNode || $argument instanceof SqlRef) || $span === null) {
                        continue;
                    }

                    $offset = $fragment->fragment->fileOffsetOf($span->offset);

                    if ($offset >= $start && $offset <= $end) {
                        $hints[] = ['position' => $document->positionAt($offset), 'label' => $name.':', 'kind' => self::PARAMETER, 'paddingRight' => true];
                    }
                }
            }
        }

        return $hints;
    }

    /**
     * Every condition and template $fragment's text calls with arguments: the
     * name it is called by, and the arguments.
     *
     * @return list<array{0: NameNode, 1: list<INode>}>
     */
    private static function callsIn(AnalyzedFragment $fragment): array
    {
        $found = [];
        $pending = $fragment->analysis->syntax->children;

        while ($pending !== []) {
            $node = array_pop($pending);

            if ($node instanceof ConditionNode && $node->parameters !== []) {
                $found[] = [$node->conditionKey, $node->parameters];
            } elseif ($node instanceof IncludeInvocationNode && $node->arguments !== []) {
                $found[] = [$node->templateKey, $node->arguments];
            }

            array_push($pending, ...$node->children());
        }

        return $found;
    }

    /**
     * The names of the parameters of the condition or template $callee names,
     * looked up where the name is written; empty when it is not declared there.
     *
     * @return list<string>
     */
    private static function parameterNames(Workspace $workspace, AnalyzedFragment $fragment, NameNode $callee): array
    {
        $offset = $callee->location->span?->offset;

        if ($offset === null) {
            return [];
        }

        $located = new Located($fragment, $offset, CursorContext::at(
            $fragment->analysis->syntax,
            $offset,
            $workspace->catalog(),
            $fragment->fragment->schemaKey,
        ));

        return $located->declared($workspace->vocabulary())[1]->parameters ?? [];
    }
}
