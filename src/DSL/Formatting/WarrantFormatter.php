<?php

namespace Warrant\DSL\Formatting;

use LogicException;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\AnalysisResult;
use Warrant\DSL\Parsing\WarrantParser;

/**
 * Formats Warrant rule text in the one house style `specs/formatter.md` sets
 * out, with no settings.
 *
 * Formatting is about syntax only: no schema is consulted, and nothing is
 * validated or expanded. Text with a syntax error is refused. Every result is
 * checked before it is answered: it must parse to the same tree, keep every
 * comment in order, and come out of a second formatting unchanged. Text that
 * fails any of these is refused rather than changed.
 */
final class WarrantFormatter
{
    /**
     * $text in the house style.
     *
     * @throws RuleTextFormattingException When $text has a syntax error, or
     *   could not be formatted without changing it.
     */
    public function format(string $text): string
    {
        return $this->checked($text, false);
    }

    /**
     * $text in the house style, all on one line, with no width to fit in: for a
     * quoted PHP string, which holds rule text on one line.
     *
     * @throws RuleTextFormattingException When $text has a syntax error or a
     *   comment, or could not be formatted without changing it.
     */
    public function formatOnOneLine(string $text): string
    {
        return $this->checked($text, true);
    }

    private function checked(string $text, bool $onOneLine): string
    {
        $analysis = self::analysed($text);

        if ($onOneLine && self::comments($analysis->tokens) !== []) {
            throw RuleTextFormattingException::hasComments();
        }

        $formatted = self::laidOut($text, $analysis, $onOneLine);
        $reread = self::analysed($formatted);

        if (SyntaxShape::of($reread->syntax) !== SyntaxShape::of($analysis->syntax)) {
            throw RuleTextFormattingException::notKept('what the rules say');
        }

        if (self::comments($reread->tokens) !== self::comments($analysis->tokens)) {
            throw RuleTextFormattingException::notKept('its comments');
        }

        if (self::laidOut($formatted, $reread, $onOneLine) !== $formatted) {
            throw RuleTextFormattingException::notKept('the layout a second time');
        }

        return $formatted;
    }

    private static function analysed(string $text): AnalysisResult
    {
        $analysis = WarrantParser::analyze($text);

        if ($analysis->diagnostics !== []) {
            throw RuleTextFormattingException::syntaxErrors($text, $analysis->diagnostics);
        }

        return $analysis;
    }

    private static function laidOut(string $text, AnalysisResult $analysis, bool $onOneLine): string
    {
        try {
            return (new RuleTextLayout($text, $analysis->syntax, $analysis->tokens, $onOneLine))->layout();
        } catch (LogicException) {
            throw RuleTextFormattingException::notKept('where a comment stands');
        }
    }

    /**
     * @param list<Token> $tokens
     * @return list<string>
     */
    private static function comments(array $tokens): array
    {
        return array_values(array_map(
            static fn (Token $token): string => rtrim($token->lexeme),
            array_filter($tokens, static fn (Token $token): bool => $token->type === TokenType::COMMENT),
        ));
    }
}
