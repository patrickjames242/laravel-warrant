<?php

namespace Warrant\DSL\Parsing;

use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

/**
 * What {@see WarrantParser::analyze()} found in a text: the tree it could read,
 * everything wrong with the text, and every token of it. The tree carries the
 * text and where each of its nodes was written; the diagnostics are about the
 * reading of it, and the tokens are the text as the lexer read it, so both are
 * kept beside the tree rather than on it.
 */
final readonly class AnalysisResult
{
    /**
     * @param WarrantSyntax $syntax The tree read from the text, repaired where
     *   the text has mistakes.
     * @param list<Diagnostic> $diagnostics Every error found, in the order
     *   it was found.
     * @param list<Token> $tokens Every token of the text, in order, ending in the
     *   EOF token: the ones the tree is read from, and the comments and the
     *   unreadable text it is not, for what needs the whole text, such as
     *   highlighting, or what is written just before a place the tree has no
     *   node for.
     */
    public function __construct(
        public WarrantSyntax $syntax,
        public array $diagnostics = [],
        public array $tokens = [],
    ) {
    }
}
