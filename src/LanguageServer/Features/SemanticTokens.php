<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Positions\CursorContext;
use Warrant\LanguageServer\Workspace;
use Warrant\Schema\Constants\ConstantDefinition;

/**
 * The colours the editor gives the names in rule text that a grammar cannot
 * tell apart by their shape: the constants.
 *
 * A bare constant in an argument looks like a condition's name to a grammar,
 * and a constant naming abilities in an ability list looks like an ability.
 * Only the schemas can tell, so the language server says which names are
 * constants: every constant written in an argument, the schema prefix of one,
 * and each name in an ability list that the schema does not declare as an
 * ability but a constant stands for.
 */
final class SemanticTokens
{
    /** The token types, in the order their indexes in the data stand for. */
    public const TYPES = ['variable', 'namespace'];

    /** The token modifiers, in the order their bits in the data stand for. */
    public const MODIFIERS = ['readonly'];

    /** A constant: a variable that is read only, which editors colour as a constant. */
    private const CONSTANT = [0, 1];

    /** A schema's key, written as a constant's prefix. */
    private const SCHEMA = [1, 0];

    /**
     * The LSP legend the data of {@see of()} is read with.
     *
     * @return array{tokenTypes: list<string>, tokenModifiers: list<string>}
     */
    public static function legend(): array
    {
        return ['tokenTypes' => self::TYPES, 'tokenModifiers' => self::MODIFIERS];
    }

    /**
     * The semantic tokens of $document, as the LSP's relative integer data:
     * for each token in order, its line and start character relative to the
     * token before it, its length, its type and its modifiers.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @return list<int>
     */
    public static function of(Workspace $workspace, Document $document): array
    {
        $tokens = [];

        foreach ($workspace->analyze($document) as $fragment) {
            foreach (self::namesIn($workspace, $fragment) as [$name, $kind]) {
                $span = $name->location->span;

                if ($span === null || $span->length() === 0) {
                    continue;
                }

                $range = $document->rangeOf(
                    $fragment->fragment->fileOffsetOf($span->offset),
                    $fragment->fragment->fileOffsetOf($span->endOffset, isEnd: true),
                );

                if ($range['start']['line'] === $range['end']['line']) {
                    $tokens[] = [$range['start']['line'], $range['start']['character'], $range['end']['character'] - $range['start']['character'], ...$kind];
                }
            }
        }

        usort($tokens, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $data = [];
        $line = 0;
        $character = 0;

        foreach ($tokens as [$tokenLine, $tokenCharacter, $length, $type, $modifiers]) {
            array_push($data, $tokenLine - $line, $tokenLine === $line ? $tokenCharacter - $character : $tokenCharacter, $length, $type, $modifiers);
            $line = $tokenLine;
            $character = $tokenCharacter;
        }

        return $data;
    }

    /**
     * Every name in $fragment's text that is a constant or a constant's schema
     * prefix, each with its token type and modifiers.
     *
     * @return list<array{0: NameNode, 1: array{0: int, 1: int}}>
     */
    private static function namesIn(Workspace $workspace, AnalyzedFragment $fragment): array
    {
        $found = [];
        $pending = $fragment->analysis->syntax->children;

        while ($pending !== []) {
            $node = array_pop($pending);

            if ($node instanceof ConstantRef) {
                if ($node->schema !== null) {
                    $found[] = [$node->schema, self::SCHEMA];
                }

                $found[] = [$node->name, self::CONSTANT];
            }

            foreach (self::abilityNamesOf($node) as $ability) {
                if (self::namesConstant($workspace, $fragment, $ability)) {
                    $found[] = [$ability, self::CONSTANT];
                }
            }

            array_push($pending, ...$node->children());
        }

        return $found;
    }

    /**
     * The bare names $node writes where abilities go.
     *
     * @return list<NameNode>
     */
    private static function abilityNamesOf(INode $node): array
    {
        $abilities = match (true) {
            $node instanceof CanClauseNode, $node instanceof CannotClauseNode,
            $node instanceof AbilityBlockNode, $node instanceof IncludeInvocationNode => $node->abilities,
            $node instanceof CrossSchemaCanNode => [$node->ability],
            default => [],
        };

        return array_values(array_filter($abilities, static fn (INode $ability): bool => $ability instanceof NameNode));
    }

    /**
     * Whether the bare name $ability, written where abilities go, stands for a
     * `#[Constant]` rather than an ability.
     */
    private static function namesConstant(Workspace $workspace, AnalyzedFragment $fragment, NameNode $ability): bool
    {
        $offset = $ability->location->span?->offset;

        if ($offset === null || $ability->name === '*') {
            return false;
        }

        $located = new Located($fragment, $offset, CursorContext::at(
            $fragment->analysis->syntax,
            $offset,
            $workspace->catalog(),
            $fragment->fragment->schemaKey,
        ));

        return $located->declaration($workspace->vocabulary())?->definition instanceof ConstantDefinition;
    }
}
